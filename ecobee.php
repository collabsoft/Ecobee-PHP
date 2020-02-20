<?php

$opts = getopt( "", array( "download-all", "download-new", "date-begin:", 'thermostat-id:', 'db-path:', 'config-path:', ) );

if ( empty( $opts ) || ( ! isset( $opts['download-all'] ) && ! isset( $opts['download-new'] ) ) ) {
	die( "
This is a script I wrote to download all of the data from my Ecobee thermostats
and store it in a SQLite database so I could query it locally.

It doesn't do a lot more than that, and it's very specific to my needs, but you
may find it useful as a starting point for your own script. It is based heavily on 
Brad Richardson's ecobee-csv project: https://github.com/brad-richardson/ecobee-csv

Note: all dates are in UTC, as that's how Ecobee stores them, so you may see data in
the database for a date that appears to be in the future, depending on your timezone.

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
");
}

define( 'ECOBEE_API_KEY', 'p1hl0IGkaAs0oV1goBDWVC6o0zT5ZvMo' );

if ( ! get_config( "code" ) ) {
	register_application();
}

get_tokens();
get_thermostats();
get_data();

function get_config_file_path() {
	global $opts;
	
	if ( ! isset( $opts['config-path'] ) ) {
		return 'ecobee-config.json';
	}
	
	$path = $opts['config-path'];
	
	$path = preg_replace( '/^~/', $_SERVER['HOME'], $path );
	
	if ( file_exists( $path ) && is_dir( $path ) ) {
		// Ensure that the path ends in a separator.
		$path = rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		
		// Add a default filename.
		$path .= "ecobee-config.json";
	}
	else {
		// The user supplied their own filename.
	}
	
	return $path;
}

function get_config( $param ) {
	if ( ! file_exists( get_config_file_path() ) ) {
		return false;
	}
	
	$config_text = file_get_contents( get_config_file_path() );
	
	if ( ! $config_text ) {
		return false;
	}
	
	$config_data = json_decode( $config_text );
	
	if ( ! $config_data ) {
		return false;
	}
	
	if ( ! isset( $config_data->{ $param } ) ) {
		return false;
	}
	
	return $config_data->{ $param };
}

function set_config( $param, $value ) {
	$config_data = json_decode( file_get_contents( get_config_file_path() ) );
	$config_data->{ $param } = $value;
	
	file_put_contents( get_config_file_path(), json_encode( $config_data ) );
}

function api_request( $url ) {
	$opts = array(
		'http' => array(
			'method' => "GET",
			'header' => "Authorization: Bearer " . get_config( "access_token" ) . "\r\n" . "Content-Type: application/json;charset=UTF-8",
		)
	);

	$context = stream_context_create( $opts );

	$response = file_get_contents( $url, false, $context );
	
	return json_decode( $response );
}

function register_application() {
	echo "** Registering this application. You should only have to do this once. **\n";
	
	$config_json = new stdClass();
	
	$url = 'https://api.ecobee.com/authorize?response_type=ecobeePin&scope=smartRead&client_id=' . ECOBEE_API_KEY;
	
	$auth_response_text = file_get_contents( $url );
	$auth_response_json = json_decode( $auth_response_text );
	
	$pin = $auth_response_json->ecobeePin;
	$code = $auth_response_json->code;
	$config_json->code = $code;
	
	echo "Follow these instructions to register this application.\n";
	echo "  1. Visit https://www.ecobee.com/home/ecobeeLogin.jsp\n";
	echo "  2. Login -> Menu (right icon) -> My Apps -> Add Application -> Enter pin -> Validate -> Add Application\n";
	echo "  3. Enter this PIN: " . $pin . "\n";
	echo "  4. Press any key when finished...\n";

	$handle = fopen( "php://stdin", "r" );
	$line = fgets( $handle );
	fclose( $handle );
	
	file_put_contents( get_config_file_path(), json_encode( $config_json ) );
}

function get_tokens() {
	echo "** Getting access tokens **\n";
	
	if ( ! get_config( "refresh_token" ) ) {
		$code = get_config( "code" );
	
		$url = 'https://api.ecobee.com/token?grant_type=ecobeePin&code=' . urlencode( $code ) . '&client_id=' . ECOBEE_API_KEY;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, array());
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);
		curl_close($ch);

		$result_json = json_decode( $result );
		
		if ( isset( $result_json->error ) ) {
			if ( "authorization_expired" == $result_json->error ) {
				echo "Authorization took too long. We'll have to start over.\n";
				echo "----------------\n";
				register_application();
				get_tokens();
				return;
			}
		}

		set_config( "access_token", $result_json->access_token );
		set_config( "refresh_token", $result_json->refresh_token );
	}
	else {
		refresh_tokens();
	}
}

function refresh_tokens() {
	echo "** Refreshing access tokens. **\n";
	
	$refresh_token = get_config( "refresh_token" );
	
	$url = 'https://api.ecobee.com/token?grant_type=refresh_token&refresh_token=' . urlencode( $refresh_token ) . '&client_id=' . ECOBEE_API_KEY;
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_POSTFIELDS, array());
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$result = curl_exec($ch);
	curl_close($ch);

	$result_json = json_decode( $result );

	set_config( "access_token", $result_json->access_token );
	set_config( "refresh_token", $result_json->refresh_token );
}

function get_thermostats() {
	echo "** Getting thermostat list. **\n";
	
	$response = api_request( 'https://api.ecobee.com/1/thermostat?format=json&body={"selection":{"selectionType":"registered","selectionMatch":""}}' );

	set_config( "thermostats", $response->thermostatList );
}

function get_data() {
	global $opts;
	
	echo "** Getting data **\n";
	
	$db = get_db();
	
	$columns = array(
		"auxHeat1",
		"auxHeat2",
		"auxHeat3",
		"compCool1",
		"compCool2",
		"compHeat1",
		"compHeat2",
		"dehumidifier",
		"dmOffset",
		"economizer",
		"fan",
		"humidifier",
		"hvacMode",
		"outdoorHumidity",
		"outdoorTemp",
		"sky",
		"ventilator",
		"wind",
		"zoneAveTemp",
		"zoneCalendarEvent",
		"zoneClimate",
		"zoneCoolTemp",
		"zoneHeatTemp",
		"zoneHumidity",
		"zoneHumidityHigh",
		"zoneHumidityLow",
		"zoneHvacMode",
		"zoneOccupancy",
	);
	
	$thermostats = get_config( "thermostats" );
	
	if ( isset( $opts['thermostat-id'] ) ) {
		foreach ( $thermostats as $idx => $thermostat ) {
			if ( $thermostat->identifier != $opts['thermostat-id'] ) {
				unset( $thermostats[ $idx ] );
			}
		}
	}
	
	if ( isset( $opts['date-begin'] ) ) {
		$a = new \DateTime( $opts['date-begin'] );
		$b = new \DateTime;

		$days_ago = $a->diff( $b )->days;
	}
	else {
		$days_ago = 1;
	}
	
	$empty_days_in_a_row = array();
	
	do {
		$data_day = date( "Y-m-d", strtotime( "-" . $days_ago . " days" ) );

		$thermostat_ids = array();
	
		foreach ( $thermostats as $thermostat ) {
			$record_count_statement = $db->prepare( "SELECT COUNT(*) count FROM history WHERE thermostat_id=:thermostat_id AND date=:date" );
			$record_count_statement->bindValue( ":thermostat_id", $thermostat->identifier );
			$record_count_statement->bindValue( ":date", $data_day );
			$record_count = $record_count_statement->execute();
			
			// Skip a day if we have 288 entries for each thermostat for it -- a full day divided into 5-minute increments is 288.
			while ( $row = $record_count->fetchArray() ) {
				if ( 288 === $row["count"] ) {
					if ( isset( $opts['download-new'] ) ) {
						// @todo If data was downloaded for a single thermostat, this may cause days to be ignored for other thermostats, since it quits upon the first notice of data from any thermostat.
						echo "Finished downloading all new data.\n";
						break 3;
					}
					else {
						echo "Skipping " . $data_day . " for thermostat " . $thermostat->identifier . ". Data is already stored.\n";
					}
				}
				else {
					$thermostat_ids[] = $thermostat->identifier;
				}

				break;
			}
		}
		
		if ( ! empty( $thermostat_ids ) ) {
			echo "** Getting data for " . $data_day . ( count( $empty_days_in_a_row ) == 0 ? "" : " -- " . count( $empty_days_in_a_row ) . " empty days in a row" ) . " **\n";

			$response = api_request( 'https://api.ecobee.com/1/runtimeReport?format=json&body=' . urlencode( json_encode(
				array(
					'selection' => array( 'selectionType' => 'thermostats', 'selectionMatch' => implode( ",", $thermostat_ids ) ),
					'startDate' => $data_day,
					'endDate' => date( "Y-m-d", strtotime( "-" . ( $days_ago - 1 ) . " days" ) ),
					'startInterval' => 0,
					'endInterval' => 0,
					'columns' => implode( ",", $columns ),
				)
			) ) );

			if ( ! $response ) {
				echo "Bad response.\n";
				break;
			}
		
			if ( ! isset( $response->columns ) ) {
				echo "Missing response columns. Response:\n";
				var_dump( $response );
				break;
			}

			$response_columns = explode( ",", $response->columns );

			if ( ! isset( $response->reportList ) ) {
				echo "Missing response reportList. Response:\n";
				var_dump( $response );
				break;
			}

			foreach ( $response->reportList as $thermostat_values ) {
				if ( ! isset( $thermostat_values->rowList ) ) {
					echo "Missing rowList. Response:\n";
					var_dump( $response );
					break 2;
				}
			
				foreach ( $thermostat_values->rowList as $entry ) {
					$entry_fields = explode( "," , $entry );
				
					$date = array_shift( $entry_fields );
					$time = array_shift( $entry_fields );
			
					if ( ! array_filter( $entry_fields ) ) {
						// All of the fields are blank.
						// Note that this loop happens 288 times for each day, so keying on the date stops us from bailing if there was just a period of 50 minutes where data was lost.
						$empty_days_in_a_row[ $data_day ] = true;
						
						if ( count( $empty_days_in_a_row ) == 10 ) {
							// We've likely hit the beginning of this thermostat's data.
							echo "10 empty days in a row. Exiting the loop.\n\n";
							echo "It's possible there is just a missing section of data. If you believe that to be true, try running this again with the --date-begin=" . $data_day . "\n";
							break 3;
						}
					}
					else {
						$empty_days_in_a_row = array();
					}
				
					$insert_query = "INSERT INTO history (thermostat_id, date, time, " . implode( ", ", $response_columns ) . ") VALUES (:thermostat_id, :date, :time, :" . implode( ", :", $response_columns ) . ")";
					$insert_statement = $db->prepare( $insert_query );
					$insert_statement->bindValue( ':thermostat_id', $thermostat_values->thermostatIdentifier, SQLITE3_TEXT );
					$insert_statement->bindValue( ':date', $date, SQLITE3_TEXT );
					$insert_statement->bindValue( ':time', $time, SQLITE3_TEXT );
			
					foreach ( $response_columns as $idx => $column ) {
						$insert_statement->bindValue( ':' . $column, $entry_fields[ $idx ] );
					}

					$insert_statement->execute();
				}
			}
		}
		
		$days_ago++;
	} while ( $days_ago < 5000 ); // Just a failsafe.
	
	$db->close();
}

function get_db() {
	global $opts;

	if ( isset( $opts['db-path'] ) ) {
		$database_file = $opts['db-path'];
		
		$database_file = preg_replace( '/^~/', $_SERVER['HOME'], $database_file );
		
		if ( file_exists( $database_file ) && is_dir( $database_file ) ) {
			// Ensure that the path ends in a separator.
			$database_file = rtrim( $database_file, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
			
			// Add a default filename.
			$database_file .= "ecobee.db";
		}
		else {
			// The user supplied their own filename.
		}
	}
	else {
		// Just put it in the current directory.
		$database_file = "ecobee.db";
	}
	
	$db = new SQLite3( $database_file );
	$db->exec( "CREATE TABLE IF NOT EXISTS history ( thermostat_id INTEGER, date TEXT, time TEXT, auxHeat1 NUMBER, auxHeat2 NUMBER, auxHeat3 NUMBER, compCool1 NUMBER, compCool2 NUMBER, compHeat1 NUMBER, compHeat2 NUMBER, dehumidifier NUMBER, dmOffset NUMBER, economizer NUMBER, fan NUMBER, humidifier NUMBER, hvacMode TEXT, outdoorHumidity NUMBER, outdoorTemp NUMBER, sky NUMBER, ventilator NUMBER, wind NUMBER, zoneAveTemp NUMBER, zoneCalendarEvent TEXT, zoneClimate TEXT, zoneCoolTemp NUMBER, zoneHeatTemp NUMBER, zoneHumidity NUMBER, zoneHumidityHigh NUMBER, zoneHumidityLow NUMBER, zoneHvacMode TEXT, zoneOccupancy TEXT, UNIQUE (thermostat_id, date, time) ON CONFLICT REPLACE )" );
	$db->exec( "CREATE INDEX IF NOT EXISTS thermostat_id_index ON history (thermostat_id)" );
	
	return $db;
}