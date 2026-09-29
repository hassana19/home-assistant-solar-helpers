# Home Assistant Solar Helpers

Two small PHP endpoints that turn third-party data into clean JSON for Home Assistant:

| File | What it does |
|------|--------------|
| `peakOffPeak.php` | Scrapes the PITC [peak / off-peak timing page](https://bill.pitc.com.pk/peak-offpeak-timing), picks the row for the current season, and tells you whether it is **PEAK** or **OFF PEAK** right now (Asia/Karachi time). |
| `itelongrid-production-api.php` | Reads daily solar production for a month from an **iTel on-grid inverter** via the Aotai cloud (`aotaicloud.com`), and returns every day of the month (missing days filled with `0`). |

---

## Requirements

- PHP 7.4+ with the `curl`, `dom`/`libxml` and `calendar` extensions
- Any web server that can serve PHP (Apache, Nginx + PHP-FPM, or `php -S` for testing)
- The server must be reachable from Home Assistant

Quick local test:

```bash
php -S 0.0.0.0:8080
# http://<server-ip>:8080/peakOffPeak.php
# http://<server-ip>:8080/itelongrid-production-api.php
```

---

## 1. `peakOffPeak.php` — PITC peak timing

### Request

```
GET /peakOffPeak.php
```

No parameters.

### Response

```json
{
    "date": "2026-09-30 19:15:02",
    "current_status": "PEAK",
    "peak_timing": "6:30 PM to 10:30 PM"
}
```

| Field | Description |
|-------|-------------|
| `date` | Server time when the response was generated (Asia/Karachi) |
| `current_status` | `PEAK`, `OFF PEAK`, or `UNKNOWN` (if the season/row could not be matched) |
| `peak_timing` | Peak window for the current season, as written on the PITC site |
| `error` | Only present when PITC could not be reached and no cached copy exists |

### Notes

- The page HTML is cached in the PHP session and reused as a fallback if PITC is down.
  Home Assistant does not keep cookies between REST calls, so in practice each HA poll
  fetches PITC fresh — keep the `scan_interval` reasonable (5–15 minutes).
- Season matching handles ranges that wrap the year end (e.g. `Dec to Feb`).

---

## 2. `itelongrid-production-api.php` — iTel on-grid daily production

### Setup

Edit the credentials at the top of the file with your Aotai cloud / iTel app login:

```php
$username = "your-username";
$password = "your-password";
```

> Don't commit real credentials. Consider reading them from environment variables
> (`getenv('AOT_USER')`) or a config file outside the web root.

### Request

```
GET /itelongrid-production-api.php?year=2026&month=9
```

| Param | Default | Description |
|-------|---------|-------------|
| `year` | current year | 1970–2100 |
| `month` | current month | 1–12 |

### Response

```json
{
    "code": 0,
    "msg": "SUCCESS",
    "count": 30,
    "data": {
        "2026-09-01": 18.4,
        "2026-09-02": 21.1,
        "...": "...",
        "2026-09-30": 0
    }
}
```

- `data` has one key per day of the month (`YYYY-MM-DD`); the value is that day's production (kWh, as reported by the inverter cloud).
- Days with no data (future days, cloud errors) are `0`.
- Invalid input returns `{"code": 1, "msg": "INVALID_INPUT", ...}`.

---

## Home Assistant integration

Replace `http://192.168.1.50:8080` below with the URL where you host these files.

### REST sensors (`configuration.yaml`)

```yaml
rest:
  # --- PITC peak / off-peak ---
  - resource: http://192.168.1.50:8080/peakOffPeak.php
    scan_interval: 300          # every 5 minutes
    timeout: 20
    sensor:
      - name: "Electricity Tariff Status"
        unique_id: pitc_tariff_status
        value_template: "{{ value_json.current_status }}"
        icon: >
          {{ 'mdi:flash-alert' if value_json.current_status == 'PEAK' else 'mdi:flash-outline' }}
      - name: "Peak Timing"
        unique_id: pitc_peak_timing
        value_template: "{{ value_json.peak_timing | default('Unknown') }}"
        icon: mdi:clock-time-eight-outline
    binary_sensor:
      - name: "Peak Hours"
        unique_id: pitc_peak_hours
        value_template: "{{ value_json.current_status == 'PEAK' }}"
        icon: mdi:transmission-tower

  # --- iTel on-grid inverter production (current month) ---
  - resource: http://192.168.1.50:8080/itelongrid-production-api.php
    scan_interval: 900          # every 15 minutes
    timeout: 20
    sensor:
      - name: "Solar Production Month Data"
        unique_id: itel_solar_month_data
        value_template: "{{ value_json.count }}"
        json_attributes:
          - data
      - name: "Solar Production Today"
        unique_id: itel_solar_today
        unit_of_measurement: kWh
        device_class: energy
        state_class: total_increasing
        value_template: >
          {{ value_json.data[now().strftime('%Y-%m-%d')] | float(0) }}
      - name: "Solar Production This Month"
        unique_id: itel_solar_month_total
        unit_of_measurement: kWh
        device_class: energy
        state_class: total_increasing
        value_template: >
          {{ value_json.data.values() | map('float', 0) | sum | round(2) }}
```

`Solar Production Today` uses `state_class: total_increasing`, so it can be added to the
**Energy dashboard** as a solar production source (it resets to 0 each day, which HA handles).

### Template helper — yesterday's production

```yaml
template:
  - sensor:
      - name: "Solar Production Yesterday"
        unique_id: itel_solar_yesterday
        unit_of_measurement: kWh
        device_class: energy
        state: >
          {% set d = state_attr('sensor.solar_production_month_data', 'data') or {} %}
          {{ d.get((now() - timedelta(days=1)).strftime('%Y-%m-%d'), 0) | float(0) }}
```

(On the 1st of the month this returns 0, because the endpoint only returns the current month.)

### Calling the API on demand (`rest_command`)

Useful for fetching a specific month from a script or automation:

```yaml
rest_command:
  itel_production_month:
    url: "http://192.168.1.50:8080/itelongrid-production-api.php?year={{ year }}&month={{ month }}"
    method: GET
    timeout: 20

  pitc_peak_status:
    url: "http://192.168.1.50:8080/peakOffPeak.php"
    method: GET
```

Example script using the response (HA 2024.1+):

```yaml
script:
  get_last_month_solar:
    sequence:
      - action: rest_command.itel_production_month
        data:
          year: "{{ (now().replace(day=1) - timedelta(days=1)).year }}"
          month: "{{ (now().replace(day=1) - timedelta(days=1)).month }}"
        response_variable: resp
      - action: persistent_notification.create
        data:
          title: "Last month's solar"
          message: >
            {{ resp.content.data.values() | map('float', 0) | sum | round(1) }} kWh
```

To force a refresh of the REST sensors, call `homeassistant.update_entity` on any of the sensors above.

### Automation example — alert when peak hours start

```yaml
automation:
  - alias: "Peak hours started"
    trigger:
      - platform: state
        entity_id: binary_sensor.peak_hours
        to: "on"
    action:
      - action: notify.notify
        data:
          message: >
            Peak hours started ({{ states('sensor.peak_timing') }}). Turn off heavy loads.
```

### Dashboard cards

```yaml
type: vertical-stack
cards:
  - type: entities
    title: Electricity Tariff
    entities:
      - entity: sensor.electricity_tariff_status
        name: Current status
      - entity: sensor.peak_timing
        name: Peak window
  - type: glance
    title: Solar Production
    entities:
      - entity: sensor.solar_production_today
        name: Today
      - entity: sensor.solar_production_yesterday
        name: Yesterday
      - entity: sensor.solar_production_this_month
        name: This month
```

Optional daily bar chart for the month (needs [ApexCharts Card](https://github.com/RomRider/apexcharts-card) from HACS):

```yaml
type: custom:apexcharts-card
header:
  show: true
  title: Solar Production – This Month
graph_span: 1month
span:
  start: month
series:
  - entity: sensor.solar_production_month_data
    type: column
    name: kWh
    data_generator: |
      const d = entity.attributes.data || {};
      return Object.entries(d).map(([k, v]) => [new Date(k).getTime(), v]);
```

---

## Troubleshooting

- **`UNKNOWN` status** — PITC changed its page layout (the scraper looks for
  `table.table-bordered > tbody > tr`), or the current month isn't in any season row.
- **All zeros from the inverter endpoint** — wrong credentials, or the Aotai cloud is
  unreachable. Call `https://www.aotaicloud.com/solarweb/user/getMonthBar?...` directly to check.
- **Sensors `unavailable` in HA** — check the URL from the HA host
  (`curl http://<server>/peakOffPeak.php`) and look in *Settings → System → Logs*.
- After editing YAML, restart Home Assistant (or reload *REST entities* / *Template entities*
  from Developer Tools → YAML).
