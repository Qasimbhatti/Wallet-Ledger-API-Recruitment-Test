VUs: 100 (hot phase: 70 on user_000000 + 30 bystanders), duration per phase: 60s
Total requests: 131412 (1049 req/s), unexpected responses: 0

| phase_endpoint    | requests |  p50 ms  |  p95 ms  |  p99 ms  |  max ms  |
|-------------------|---------:|---------:|---------:|---------:|---------:|
| mixed_credit      |    28573 |    82.54 |   148.29 |   197.49 |   351.96 |
| mixed_debit       |    14176 |    81.39 |   144.62 |   199.07 |   306.98 |
| mixed_balance     |    28260 |    76.26 |   141.66 |   192.64 |   307.51 |
| hot_credit        |     3972 |   380.74 |  1806.25 |  2941.57 |  6036.81 |
| hot_debit         |     3895 |   311.39 |  1535.55 |  2329.14 |  4281.26 |
| hot_balance       |      928 |    31.25 |    57.64 |    87.01 |   110.69 |
| bystander_credit  |    20618 |    35.58 |    62.00 |    83.82 |   200.00 |
| bystander_debit   |    10242 |    34.98 |    61.50 |    81.44 |   173.13 |
| bystander_balance |    20748 |    27.74 |    54.03 |    71.00 |   162.75 |
