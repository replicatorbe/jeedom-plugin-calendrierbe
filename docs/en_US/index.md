# Schedule calendar

This plugin shows in a calendar everything Jeedom is going to run: scheduled
scenarios, pending AT and IN blocks, delayed command tasks, plugin tasks and
Jeedom's own tasks. It changes nothing; it reads what the core has scheduled
and computes it exactly as the core does.

Open it from **Home → Schedule calendar**. Month, week and day views; click a
day to see every trigger, hour by hour, with a link to the scenario or device.

Plugins can publish their own schedule with a static method
`calendrierbeEvents($_from, $_to)` returning a list of
`array('ts' => …, 'title' => …, 'detail' => …, 'link' => …)`. Info commands
whose logical id starts with `next` and whose value is a full date are also
shown.

Cannot be predicted: event-triggered scenarios, `sleep` and `wait` inside a
running scenario, and schedules a plugin computes in its `cron()` without
exposing them.
