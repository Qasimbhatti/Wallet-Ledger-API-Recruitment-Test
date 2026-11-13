VUs: 100 (hot phase: 70 on user_000000 + 30 bystanders), duration per phase: 60s
Total requests: 114337 (912 req/s), unexpected responses: 0

| phase_endpoint    | requests |  p50 ms  |  p95 ms  |  p99 ms  |  max ms  |
|-------------------|---------:|---------:|---------:|---------:|---------:|
| mixed_credit      |    23779 |    97.11 |   173.13 |   239.73 |   907.51 |
| mixed_debit       |    12066 |    92.91 |   164.32 |   236.69 |   950.75 |
| mixed_balance     |    23786 |    91.64 |   168.01 |   244.18 |   983.17 |
| hot_credit        |     2804 |   562.96 |  2586.21 |  4041.85 |  7167.81 |
| hot_debit         |     2744 |   433.34 |  2057.24 |  3160.51 |  5204.03 |
| hot_balance       |      628 |    31.36 |    59.43 |    76.93 |    94.00 |
| bystander_credit  |    19603 |    39.67 |    66.09 |    84.83 |  1433.69 |
| bystander_debit   |     9842 |    33.99 |    61.20 |    78.90 |   276.44 |
| bystander_balance |    19085 |    29.21 |    55.78 |    73.69 |   228.60 |
