# ecobee.php

This is a script I wrote to download all of the data from my Ecobee thermostats and store it in a SQLite database so I could query it locally.

It doesn't do a lot more than that, and it's very specific to my needs, but you may find it useful as a starting point for your own script. It is based heavily on Brad Richardson's ecobee-csv project: https://github.com/brad-richardson/ecobee-csv

Note: all dates are in UTC, as that's how Ecobee stores them, so you may see data in the database for a date that appears to be in the future, depending on your timezone.

Usage:

     php ecobee.php [options]

Options:

     --download-all               Download all of the data that is available for your thermostats
                                  and save it to a SQLite database. It will begin with yesterday and
                                  download a day's worth of data at a time until it finds 10 days in
                                  a row with no data available. This may take a while.

     --download-new               Download any data newer than the last data that was saved to the
                                  SQLite database.

     --date-begin=YYYY-MM-DD      If either of the --download-* flags are present, begin downloading
                                  data from this date, moving back in time. Otherwise, it will begin
                                  yesterday.

     --thermostat-id              Only download data for this thermostat. The thermostat ID can be found
                                  in ecobee-config.json in the 'identifier' parameter, or in the ecobee.com URLs
                                  like https://www.ecobee.com/consumerportal/index.html#/devices/thermostats/12345678

     --db-path=/path/to/file      Use this path for the SQLite database file. If a directory is supplied,
                                  a file named ecobee.db will be created in it.

     --config-path=/path/to/file  Use this path for the config JSON file. If a directory is supplied,
                                  a file named ecobee-config.json will be created in it.

## Example Queries

Here are some example queries you could run on the SQLite database that this script creates. The charts were created by pasting the query results into Google Sheets and using the chart creation tool there.

### Number of days that each temp was the low temp.

```
SELECT
	lowOutdoorTemp,
	COUNT(*) numberOfDays
FROM (
	SELECT
		ROUND(MIN(outdoorTemp)) lowOutdoorTemp
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY date
	)
GROUP BY lowOutdoorTemp
ORDER BY lowOutdoorTemp ASC
```

![](/charts/daily-low-temperatures.png)

### Number of days that each temp was the high temp.

```
SELECT
	highOutdoorTemp,
	COUNT(*) numberOfDays
FROM (
	SELECT
		ROUND(MAX(outdoorTemp)) highOutdoorTemp
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY date
	)
GROUP BY highOutdoorTemp
ORDER BY highOutdoorTemp ASC
```

![](/charts/daily-high-temperatures.png)


### Average number of seconds the heat ran on a day with that high temp.

```
SELECT
	highOutdoorTemp,
	ROUND(AVG(totalHeatTime)) heatingSeconds,
	ROUND(AVG(totalAuxHeatTime)) auxHeatingSeconds
FROM (
	SELECT
		ROUND(MAX(outdoorTemp)) highOutdoorTemp,
		SUM(compHeat1) + SUM(compHeat2) + SUM(auxHeat1) + SUM(auxHeat2) + SUM(auxHeat3) totalHeatTime, /* If you have a 2-stage furnace and 3-stage backup heat, you could theoretically have 5x 24 hours of heating time in a day if everything was running. */
		SUM(auxHeat1) + SUM(auxHeat2) + SUM(auxHeat3) totalAuxHeatTime
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY date
	)
GROUP BY highOutdoorTemp
ORDER BY highOutdoorTemp ASC
```

![](/charts/furnace-runtime-vs-high-temp.png)

### Average number of seconds the a/c ran on a day with that low temp.

```
SELECT
	lowOutdoorTemp,
	ROUND(AVG(totalCoolTime)) coolingSeconds
FROM (
	SELECT
		ROUND(MIN(outdoorTemp)) lowOutdoorTemp,
		SUM(compCool1) + SUM(compCool2) totalCoolTime
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY date
	)
GROUP BY lowOutdoorTemp
ORDER BY lowOutdoorTemp ASC
```

![](/charts/ac-runtime-vs-low-temp.png)

### Average number of seconds the a/c ran on a day with that high temp.

```
SELECT
	highOutdoorTemp,
	ROUND(AVG(totalCoolTime)) coolingSeconds
FROM (
	SELECT
		ROUND(MAX(outdoorTemp)) highOutdoorTemp,
		SUM(compCool1) + SUM(compCool2) totalCoolTime
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY date
	)
GROUP BY highOutdoorTemp
ORDER BY highOutdoorTemp ASC
```

![](/charts/ac-runtime-vs-high-temp.png)

### Average number of seconds the HVAC ran based on the current outdoor temp.

```
SELECT
	roundedOutdoorTemp,
	ROUND( totalHeatTime / intervals * 12 ) heatSecondsPerHour,
	ROUND( totalCoolTime / intervals * 12 ) coolSecondsPerHour
FROM (
	SELECT
		COUNT(*) intervals,
		ROUND(outdoorTemp) roundedOutdoorTemp,
		SUM(compHeat1) + SUM(compHeat2) + SUM(auxHeat1) + SUM(auxHeat2) + SUM(auxHeat3) totalHeatTime,
		SUM(compCool1) + SUM(compCool2) totalCoolTime
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
	GROUP BY roundedOutdoorTemp
	)
ORDER BY roundedOutdoorTemp
```

![](/charts/hvac-runtime.png)

### Average number of seconds an individual HVAC system (thermostat ID #12345678) ran based on the current outdoor temp.

```
SELECT
	roundedOutdoorTemp,
	ROUND( totalHeatTime / intervals * 12 ) heatSecondsPerHour,
	ROUND( totalCoolTime / intervals * 12 ) coolSecondsPerHour
FROM (
	SELECT
		COUNT(*) intervals,
		ROUND(outdoorTemp) roundedOutdoorTemp,
		SUM(compHeat1) + SUM(auxHeat1) totalHeatTime,
		SUM(compCool1) totalCoolTime
	FROM history
	WHERE outdoorTemp <> '' /* A blank outdoorTemp value is missing data, not a zero-degree temp. */
		AND thermostat_id=12345678
	GROUP BY roundedOutdoorTemp
	)
ORDER BY roundedOutdoorTemp
```

![](/charts/individual-hvac-runtime.png)
