#!/usr/bin/env php
<?php
//require __DIR__.'/vendor/autoload.php';
require "/var/www/stackr.test/vendor/autoload.php";

use Nrwtaylor\StackAgentThing;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// Added persistent user level memory. Until power off.

(new Application("Agent", "0.2.0"))
    ->register("agent")

    ->addArgument("message", InputArgument::IS_ARRAY, "Datagram message")
    ->addOption(
        "channel",
        null,
        InputOption::VALUE_REQUIRED,
        "Which channel response should be used?",
        false
    )
    ->addOption(
        "handler",
        null,
        InputOption::VALUE_REQUIRED,
        "Which short message handler should be used?",
        false
    )
    ->addOption(
        "meta",
        null,
        InputOption::VALUE_REQUIRED,
        "What meta information should be shown?",
        false
    )
    ->addOption(
        "from",
        null,
        InputOption::VALUE_REQUIRED,
        "What from address should be used?",
        false
    )
    ->addOption(
        "log",
        null,
        InputOption::VALUE_REQUIRED,
        "What logging should be displayed?",
        false
    )
    ->addOption(
        "watch",
        null,
        InputOption::VALUE_REQUIRED,
        'What to watch for ie --watch="+warning"',
        false
    )

    ->addOption(
        "regex",
        null,
        InputOption::VALUE_REQUIRED,
        'Watch for a regex-based match ie --regex="/warning/"',
        false
    )

    ->addOption(
        "flag-error",
        null,
        InputOption::VALUE_NONE,
        "Trigger error code response. Use with --watch and --regex"
    )
    ->addOption(
        "show-channels",
        null,
        InputOption::VALUE_NONE,
        "Show the available response channels."
    )
    ->addOption(
        "show-urls",
        null,
        InputOption::VALUE_NONE,
        "Show the available response channels."
    )

    ->setCode(function (InputInterface $input, OutputInterface $output) {
        echo "thing-ship 1.0.0 16 November 2022 start\n";
        $discord_message_period = 60 * 5;
        //        $snapshot_period = 0.05; //-1

        $snapshot_period = 0.01; // 100Hz //-1

        $error_code = 0;

        $flag_match = 1; // Match not found.

        $default_handler = "php";
        $default_channel = "sms";
        $default_meta = "stack";
        $default_from = "default_console_user";
        $default_log = "off";
        $default_watch = false;
        $default_regex = false;
        $default_channels = "off";
        $default_error = "off";

        $log = $input->getOption("log");

        if ($log == false) {
            $log = $default_log;
        }

        $watch = $input->getOption("watch");

        if ($watch == false) {
            $watch = $default_watch;
        }

        $message = $input->getArgument("message");

        $message = implode(" ", $message);

        $thing = new \Nrwtaylor\StackAgentThing\Thing(null);

        // Handle stream as agent_input?

        $agent_input = null;

        $readStreams = [STDIN];
        $writeStreams = [];
        $exceptStreams = [];
        $streamCount = stream_select(
            $readStreams,
            $writeStreams,
            $exceptStreams,
            0
        );

        $hasStdIn = $streamCount === 1;

        if ($hasStdIn) {
            $f = fopen("php://stdin", "r");
            $agent_input = "";
            while ($line = fgets($f)) {
                $agent_input .= $line;
            }
            fclose($f);
            // Read content from STDIN ...
        }

        // Load settings file
        // See if there is an identity.

        $settings_file = __DIR__ . "/private/settings.php";
        if (file_exists($settings_file)) {
            $settings = require $settings_file;
        }

        $from = strtolower($input->getOption("from"));

        if ($from == "") {
            $from = "kokopelli iv";
        }

        if (isset($settings["settings"]["agent"]["default_from"])) {
            $default_from = $settings["settings"]["agent"]["default_from"];
            // TODO Command line option
        }

        if ($from == false) {
            $from = $default_from;
        }

        $uuid = $thing->getUuid();

        if ($from == "<random non-persistent>") {
            $from = "console_" . $uuid;
        }

        $to = "agent";

        $thing->Create($from, $to, "ship");

        $thing->console(
            "kplex-thing thing watch " .
                $watch .
                " log " .
                $log .
                " handler " .
                $handler .
                "\n"
        );

        // THis is development code to list to the tcp port directly nmea messages.
        // Have focused mostly on UDP as this is a more resilient station to station
        // broadcast for individual things to use.
        $tcp_flag = false;
        if ($tcp_flag === true) {
            $thing->console(
                "start thing-ship-nmea kplex TCP listener thing.\n"
            );
            //echo "Start kplex TCP listener thing.\n";
            //        $address = "192.168.10.125";
            $address = "192.168.10.10";
            $port = "10110";
            $fp = fsockopen($address, $port, $errno, $errstr, 30);

            if (!$fp) {
                echo "$errstr ($errno)<br />\n";
                //            die();
                echo "Not connected to kplex TCP server.\n";
            } else {
                echo "Connected to kplex TCP server.\n";
            }
            echo "Start kplex UDP listener thing.\n";
        }

        $ship_handler = new \Nrwtaylor\StackAgentThing\Ship($thing, "ship");
        $ship_handler->readSubject("ships");

        printShip($thing, $ship_handler);

        if (substr($ship_handler->thing_report["sms"], 0, 6) == "SHIP X") {
            $thing = new \Nrwtaylor\StackAgentThing\Thing(null);
            $thing->Create($from, $to, "new ship");
            $thing->console("ship-thing created new ship thing\n");
            $ship_handler = new \Nrwtaylor\StackAgentThing\Ship($thing, "ship");
        }

        $thing->console("ship-thing starting " . "\n");

        $sms = $ship_handler->thing_report["sms"];

        $ship_id = substr($sms, 5, 4);
        $uuid_handler = new \Nrwtaylor\StackAgentThing\Uuid($thing, "uuid");
        $uuid = $uuid_handler->extractUuid($sms);

        $thing->console("Ready.");

        $datagram_stack = [];
        $unrecognized_sentences = [];

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$socket) {
            die("socket_create failed.n");
        }

        //Set socket options.
        socket_set_nonblock($socket);
        //        socket_set_block($socket);

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (defined("SO_REUSEPORT")) {
            socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        }

        //Bind to any address & port 12345.
        if (!socket_bind($socket, "0.0.0.0", 10110)) {
            die("socket_bind failed.n");
        }

        //Wait for data.
        $read = [$socket];
        $write = [];
        $except = [];
        $seconds = 0;
        $microseconds = 0;

        $thing->console("ship-thing socket connected " . "\n");

        $discord_handler = new \Nrwtaylor\StackAgentThing\Discord(
            null,
            "discord"
        );

        $degree_handler = new \Nrwtaylor\StackAgentThing\Degree(
            null,
            "degree"
        );


        $data_thing = new \Nrwtaylor\StackAgentThing\Thing(null);
        $data_thing->Create("kokopelli", "datalog", "log");

        //while (true) {
        //$read = [$socket];
        //$write = [];
        //$except = [];
        //$seconds = 0;
        //$microseconds = 0;

        $m = socket_select($read, $write, $except, $seconds, $microseconds);
        if ($m < 1) {
            // experimetning with CPU % top. This has no effect.
            //usleep(10000);
            //      continue;
        }
        $loop_count = 0;
        $udp_count = 0;
        $packet_count = 0;
        $microtime_loop = microtime(true);

        while (true) {
            $loop_time = microtime(true) - $microtime_loop;
            $microtime_loop = microtime(true);

            $loop_count += 1;

            $udp_packet = is_string($data = socket_read($socket, 5120));

            if ($tcp_flag) {
                $tcp_packet = ($buffer = fgets($fp, 4096)) !== false;
            }

            //Read received packets with a maximum size of 5120 bytes.
            //       while (is_string($data = socket_read($socket, 5120))) {

            if ($udp_packet) {
                $udp_count += 1;
                $udp_time = microtime(true) - $microtime_udp;
                $microtime_udp = microtime(true);

                if ($data == "") {
                    continue;
                }
                $buffer = $data;

                // Call the ship handler and have it read the NMEA string
                // It will generate a variable with the current ship state as read.
                $response = $ship_handler->readShip($buffer);

                $loop_count = 0;

                // Get the last recognized sentence
                // THis lets this client sample the NMEA dataflow.
                $recognized_sentence =
                    $ship_handler->ship_thing->variables->snapshot
                        ->recognized_sentence;

                $sentence_identifier =
                    $ship_handler->ship_thing->variables->snapshot
                        ->sentence_identifier;

                if ($recognized_sentence === "N") {
                    if (
                        !in_array($sentence_identifier, $unrecognized_sentences)
                    ) {
                        $unrecognized_sentences[] = $sentence_identifier;
                    }
                    continue;
                }

                $datagram_stack = stack(
                    $datagram_stack,
                    ["subject" => $buffer],
                    1000
                );

                // Look for previous recent occurences of the same string.
                // To filter out and reduce processing burden.
/*
                $count = 0;
                foreach ($datagram_stack as $u => $datagram) {
                    //var_dump($u);
                    if ($buffer === $datagram["subject"]) {
                        //$thing->console("Saw " .$datagram['subject'] . "\n");
                        $count += 1;
                    }
                }

                if ($count > 3) {
                    //$thing->console("Saw +3 of " .$datagram['subject'] . "\n");

                    continue;
                }
$packet_count += 1;
*/
                //var_dump("stack", $datagram_stack);
                $snapshot = $ship_handler->ship_thing->variables->snapshot;

                //                outputVariable($thing, $snapshot);
                //                printStack($thing, $datagram_stack);
                $ship_handler->set();
                $displayFlag = "off";
                if (
                    microtime(true) - $microtime_display > 1.0 and
                    $displayFlag === "on"
                ) {
                    $thing->console("sms " . $sms . "\n");
                    //$thing->console("Last set time: " . $last_set_time . "\n");
                    $thing->console(
                        "Last response: " . $ship_handler->response . "\n"
                    );
                    $thing->console(
                        "Thing UUID: " . $ship_handler->uuid . "\n"
                    );

                    outputVariable($thing, $snapshot);
                    printStack($thing, $datagram_stack);

                    printMemory($thing, $ship_handler);

                    $microtime_display = microtime(true);
                }

                $statusFlag = "on";
                if (
                    microtime(true) - $microtime_display > 1.0 and
                    $statusFlag === "on"
                ) {
                    $thing->console(
                        "ship-thing ship handler text " .
                            $ship_handler->text .
                            "\n"
                    );

                    $thing->console(
                        "ship-thing udp count " . $udp_count . " packet count " . $packet_count .  "\n"
                    );
                    // Reset UDP counter
                    $udp_count = 0;
                    $packet_count = 0;

                    if (count($unrecognized_sentences) > 0) {
                        $thing->console(
                            "ship-thing unrecognized sentences " .
                                implode(" ", $unrecognized_sentences) .
                                "\n"
                        );
                    }

                    $microtime_display = microtime(true);
                }

                $memoryFlag = "on";
                if (
                    microtime(true) - $microtime_memory > 1.0 and
                    $memoryFlag === "on"
                ) {
                    printMemory($thing, $ship_handler);
                    $microtime_memory = microtime(true);
                }

                $messageShipInterval = 2;
                $messageShipFlag = "off";
                if (
                    microtime(true) - $microtime_message >
                        $messageShipInterval and
                    $messageShipFlag === "on"
                ) {
                    //$text = $recognized_sentence;
                    $text = $ship_handler->text;
                    $thing->console("ship-thing message text " . $text . "\n");

                    $ship_handler->forgetResponse();
                    $ship_handler->readSubject($text);
                    $sms = $ship_handler->thing_report["sms"];
                    $run_time = $ship_handler->thing_report["run_time"];

                    $thing->console(
                        "ship-thing message sms " .
                            $sms .
                            " " .
                            $runtime .
                            " ms" .
                            "\n"
                    );

                    $microtime_message = microtime(true);
                }

                $snapshot_master = json_decode(json_encode($snapshot), true);

                $json = json_encode($snapshot_master);
                $bytes = 0;
                if ($snapshot_period != -1) {
                    if (
                        microtime(true) - $microtime_snapshot >
                        $snapshot_period
                    ) {
                        $bytes = file_put_contents(
                            "/var/www/kplex-thing/snapshot.json",
                            $json
                        );
                        $microtime_snapshot = microtime(true);
                    }
                }

                $discordFlag = "on";
                if (
                    microtime(true) - $microtime_log >
                        $discord_message_period and
                    $discordFlag === "on"
                ) {
                    // Dev Log with mongo/express stack.

                    // Send input to stack express node server.
                    $transducers = $snapshot_master["transducers"];
                    //var_dump($snapshot_master);
                    $fix_sms = "FIX ";
                    $fix_sms .= "Time " . $snapshot_master["fix_time"] . " ";
                    //exit();
                    $fix_sms .=
                        "Timestamp " . $snapshot_master["time_stamp"] . " ";
                    $fix_sms .=
                        "Datestamp " . $snapshot_master["date_stamp"] . " ";
                    $fix_sms .=
                        "Quality " . $snapshot_master["fix_quality"] . " ";
                    $fix_sms .=
                        "Latitude " .
                        $degree_handler->decimalToDegree($snapshot_master["current_latitude_decimal"]) .
                        " ";
                    $fix_sms .=
                        "Longitude " .
                        $degree_handler->decimalToDegree($snapshot_master["current_longitude_decimal"]);

                    $m = "TRANSDUCERS ";
                    foreach ($transducers as $i => $j) {
                        //$m .= " " . $i . $j['name'] . " " . $j['amount'];
                        $m .= $j["name"] . " " . $j["amount"] . " ";
                    }

                    //                    $discord_handler = new \Nrwtaylor\StackAgentThing\Discord(
                    //                        null,
                    //                        "discord"
                    //                    );
                    $discord_handler->sendDiscord(
                        $m,
                        "kokopelli:#general@kaiju.discord"
                    );

                    $discord_handler->sendDiscord(
                        $fix_sms,
                        "kokopelli:#general@kaiju.discord"
                    );


    $alert_handler = new \Nrwtaylor\StackAgentThing\Alert($thing, "alert");
                    $discord_handler->sendDiscord(
                        $alert_handler->sms_message,
                        "kokopelli:#general@kaiju.discord"
                    );



                    $thing->console("ship-thing log discord done");

                    $whitefoxFlag = true;
                    if ($whitefoxFlag) {
                        $array = ["merp" => "merp"];
                        $response = file_get_contents(
                            "http://192.168.10.10/api/whitefox/" . $buffer
                        );
                    }

                    $thing->console("ship-thing log whitefox done");

                    //$response = file_get_contents("http://localhost:3001/" . $buffer);
                    //var_dump($response);
                    //$data_thing = new \Nrwtaylor\StackAgentThing\Thing(null);
                    //$data_thing->Create("kokopelli iv", "datalog", "log");

                    $data_thing->json->setField("variables");
                    $data_thing->json->writeVariable(
                        ["snapshot"],
                        $snapshot_master
                    );

                    $thing->console(
                        "ship-thing json write variable snapshot done"
                    );

                    $microtime_log = microtime(true);
                }
            }

            // End of loop
        }

        if (!feof($fp)) {
            echo "Error: unexpected fgets() fail\n";
        }
        fclose($fp);

        return 0;
    })
    ->getApplication()
    ->setDefaultCommand("agent", true) // Single command application
    ->run();

/*

Custom log response for command line agent.

*/

function drupal_array_merge_deep()
{
    $args = func_get_args();
    return drupal_array_merge_deep_array($args);
}

// source : https://api.drupal.org/api/drupal/includes%21bootstrap.inc/function/drupal_array_merge_deep_array/7.x
function drupal_array_merge_deep_array($arrays)
{
    $result = [];
    foreach ($arrays as $array) {
        foreach ($array as $key => $value) {
            // Renumber integer keys as array_merge_recursive() does. Note that PHP
            // automatically converts array keys that are integer strings (e.g., '1')
            // to integers.
            if (is_integer($key)) {
                $result[] = $value;
            } elseif (
                isset($result[$key]) &&
                is_array($result[$key]) &&
                is_array($value)
            ) {
                $result[$key] = drupal_array_merge_deep_array([
                    $result[$key],
                    $value,
                ]);
            } else {
                $result[$key] = $value;
            }
        }
    }
    return $result;
}

function array_merge_recursive_distinct2()
{
    $arrays = func_get_args();
    $base = array_shift($arrays);
    if (!is_array($base)) {
        $base = empty($base) ? [] : [$base];
    }
    foreach ($arrays as $append) {
        if (!is_array($append)) {
            $append = [$append];
        }
        foreach ($append as $key => $value) {
            if (!array_key_exists($key, $base) and !is_numeric($key)) {
                $base[$key] = $append[$key];
                continue;
            }
            if (is_array($value) or is_array($base[$key])) {
                $base[$key] = array_merge_recursive_distinct(
                    $base[$key],
                    $append[$key]
                );
            } elseif (is_numeric($key)) {
                if (!in_array($value, $base)) {
                    $base[] = $value;
                }
            } else {
                $base[$key] = $value;
            }
        }
    }
    return $base;
}

function array_merge_recursive_leftsource(&$a1, &$a2)
{
    $newArray = [];
    foreach ($a1 as $key => $v) {
        if (!isset($a2[$key])) {
            $newArray[$key] = $v;
            continue;
        }

        if (is_array($v)) {
            if (!is_array($a2[$key])) {
                $newArray[$key] = $a2[$key];
                continue;
            }
            $newArray[$key] = array_merge_recursive_leftsource(
                $a1[$key],
                $a2[$key]
            );
            continue;
        }

        $newArray[$key] = $a2[$key];
    }
    return $newArray;
}

function array_merge_recursive_ex(array $array1, array $array2)
{
    $merged = $array1;

    foreach ($array2 as $key => &$value) {
        if (
            is_array($value) &&
            isset($merged[$key]) &&
            is_array($merged[$key])
        ) {
            $merged[$key] = array_merge_recursive_ex($merged[$key], $value);
        } elseif (is_numeric($key)) {
            if (!in_array($value, $merged)) {
                $merged[] = $value;
            }
        } else {
            $merged[$key] = $value;
        }
    }

    return $merged;
}

function array_merge_recursive_simple()
{
    if (func_num_args() < 2) {
        trigger_error(
            __FUNCTION__ . " needs two or more array arguments",
            E_USER_WARNING
        );
        return;
    }
    $arrays = func_get_args();
    $merged = [];
    while ($arrays) {
        $array = array_shift($arrays);
        if (!is_array($array)) {
            trigger_error(
                __FUNCTION__ . " encountered a non array argument",
                E_USER_WARNING
            );
            return;
        }
        if (!$array) {
            continue;
        }
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                if (
                    is_array($value) &&
                    array_key_exists($key, $merged) &&
                    is_array($merged[$key])
                ) {
                    $merged[$key] = call_user_func(
                        __FUNCTION__,
                        $merged[$key],
                        $value
                    );
                } else {
                    $merged[$key] = $value;
                }
            } else {
                $merged[] = $value;
            }
        }
    }
    return $merged;
}

function array_merge_recursive_distinct(array &$array1, array &$array2)
{
    $merged = $array1;

    foreach ($array2 as $key => &$value) {
        if (
            is_array($value) &&
            isset($merged[$key]) &&
            is_array($merged[$key])
        ) {
            $merged[$key] = array_merge_recursive_distinct(
                $merged[$key],
                $value
            );
        } else {
            $merged[$key] = $value;
        }
    }

    return $merged;
}

function stack($datagram_stack, $datagram, $max = 10)
{
    //    $max = 10;
    if (!isset($datagram_stack)) {
        $datagram_stack = [];
    }

    $stack_height = count($datagram_stack);

    if ($stack_height > $max) {
        array_splice($datagram_stack, 0, $stack_height - $max);
    }

    $datagram["createdAt"] = 0.0;

    $datagram_stack[] = $datagram;
    //echo "datagram stack length " . count($datagram_stack) . "\r\n";
    return $datagram_stack;
}

function printStack($thing, $datagram_stack)
{
    foreach ($datagram_stack as $i => $datagram) {
        $thing->console(trim($datagram["subject"]) . "\r\n");
    }
}

function outputVariable($thing, $snapshot)
{
    printVariable($thing, $snapshot, "total_number_of_SVs_in_view");

    printVariable($thing, $snapshot, "SV_IDs", null, "Visible satellites");

    printVariable($thing, $snapshot, "transducers", null, "transducers");

    printVariable($thing, $snapshot, "fix_time");
    printVariable($thing, $snapshot, "time_stamp");
    printVariable($thing, $snapshot, "date_stamp");
    printVariable($thing, $snapshot, "fix_quality");
    printVariable($thing, $snapshot, "current_latitude_decimal");
    printVariable($thing, $snapshot, "current_longitude_decimal");

    printVariable(
        $thing,
        $snapshot,
        "altitude_above_mean_sea_level",
        "altitude_units"
    );

    printVariable(
        $thing,
        $snapshot,
        "height_of_mean_sea_level_above_WGS84_earth_ellipsoid",
        "units_of_the_geoid_seperation"
    );

    printVariable($thing, $snapshot, "speed_in_knots");
    printVariable($thing, $snapshot, "true_course");
    printVariable($thing, $snapshot, "cross_track_error_magnitude");
    printVariable($thing, $snapshot, "direction_to_steer");
    printVariable($thing, $snapshot, "cross_track_units");
    printVariable($thing, $snapshot, "destination_waypoint_latitude_decimal");
    printVariable($thing, $snapshot, "destination_waypoint_longitude_decimal");

    printVariable($thing, $snapshot, "to_waypoint_id");
    printVariable($thing, $snapshot, "from_waypoint_id");

    printVariable($thing, $snapshot, "range_to_destination_in_nautical_miles");
    printVariable($thing, $snapshot, "bearing_to_destination_in_degrees_true");

    printVariable($thing, $snapshot, "destination_closing_velocity_in_knots");
    printVariable($thing, $snapshot, "arrival_status", "arrival_status");

    printTransducers($thing, $snapshot);
}

function printArray($thing, $snapshot, $array_name = null, $label = null)
{
}

function array_depth($array)
{
    $max_indentation = 1;

    $array_str = print_r($array, true);
    $lines = explode("\n", $array_str);

    foreach ($lines as $line) {
        $indentation = (strlen($line) - strlen(ltrim($line))) / 4;

        if ($indentation > $max_indentation) {
            $max_indentation = $indentation;
        }
    }

    return ceil(($max_indentation - 1) / 2) + 1;
}

function printTransducer($transducer)
{
    echo "transducer " .
        $transducer["sensor_id"] .
        " " .
        $transducer["talker_identifier"] .
        "" .
        $transducer["type"] .
        " " .
        $transducer["name"] .
        " " .
        $transducer["amount"] .
        " " .
        $transducer["units"] .
        "\n";
}

function printTransducers($thing, $snapshot)
{
    //    $variable_name = "transducers";

    $uuid_handler = new \Nrwtaylor\StackAgentThing\Uuid($thing, "uuid");
    //        $uuid = $uuid_handler->extractUuid($sms);

    //   $transducers = $thing->transducers;
    //var_dump($snapshot->transducers);

    foreach ($snapshot->transducers as $transducer_id => $transducer) {
        //    $thing->transducers = $filtered_transducers;
        $printFlag = "off";
        if ($printFlag === "on") {
            printTransducer($transducer);
        }
        /*
        echo "transducer " .
            //                        $uuid .
            //                        " " .
            //                        $transducer_id .
            //                        " " .
            $transducer["sensor_id"] .
            " " .
            $transducer["talker_identifier"] .
            //                        "" .
            //                        $id .
            "" .
            $transducer["type"] .
            " " .
            $transducer["name"] .
            " " .
            $transducer["amount"] .
            " " .
            $transducer["units"] .
            "\n";
*/
        //var_dump($transducer);
        //$thing->transducers[$id] = $transducer;
    }

    return;
    $transducers = [];
    $transducer_flag = true;
    foreach ($snapshot as $key => $value) {
        //var_dump($key);
        if ($uuid_handler->isUuid($key)) {
            $transducer_flag = false;
            $transducer_id = $key;
            $transducers[$key] = $snapshot->{$key}["transducers"];

            // Remove this from array
            //unset($snapshot[$key]);

            //unset($snapshot->{$key});
            //var_dump($snapshot);
            //var_dump($key);
            //exit();
            //    $transducers = $snapshot->{$variable_name};
            if ($transducers[$key] == null) {
                echo "transducer null\n";
                return;
            }
        }
    }

    if ($transducer_flag === true) {
        //    if (!isset($snapshot->{$variable_name})) {
        echo "transducer not seen\n";
        return;
    }
    // Build array of sensor ids

    $sensor_ids = [];
    foreach (array_reverse($transducers) as $uuid => $transducer_group) {
        foreach ($transducer_group as $id => $transducer) {
            $sensor_id = strtolower(
                trim(
                    $transducer["talker_identifier"] .
                        $id .
                        $transducer["type"] .
                        $transducer["name"]
                )
            );
            if (!isset($sensor_ids[$sensor_id])) {
                $sensor_ids[$sensor_id] = $transducer;
            }
        }
    }
    //    if (!isset($thing->sensor_ids)) {$thing->sensor_ids = $sensor_ids;}

    $filtered_transducers = [];
    foreach ($sensor_ids as $sensor_id => $value) {
        //        if (isset($filtered_transducers[$sensor_id])) {continue;}

        foreach (array_reverse($transducers) as $uuid => $transducer_group) {
            //           var_dump($transducer_group);
            foreach ($transducer_group as $id => $transducer) {
                $check_sensor_id = strtolower(
                    trim(
                        $transducer["talker_identifier"] .
                            $id .
                            $transducer["type"] .
                            $transducer["name"]
                    )
                );
                if ($sensor_id === $check_sensor_id) {
                    $transducer["sensor_id"] = $check_sensor_id;

                    $u = $thing->getUuid();
                    $filtered_transducers[$u] = $transducer;
                    continue 3;
                }
            }
        }
    }
    //}
    //var_dump($filtered_transducers);

    //     usort($filtered_transducers, "custom_sort");
    // Define the custom sort function

    foreach ($filtered_transducers as $id => $transducer) {
        printTransducer2($transducer);
    }

    foreach ($snapshot as $key => $value) {
        if ($uuid_handler->isUuid($key)) {
            //            $transducer_flag = false;
            //            $transducer_id = $key;
            //            $transducers[$key] = $snapshot->{$key}["transducers"];

            // Remove this from array
            //unset($snapshot[$key]);

            //unset($snapshot->{$key});
            //var_dump($snapshot);
            //var_dump($key);
            //exit();
            //    $transducers = $snapshot->{$variable_name};
            //            if ($transducers[$key] == null) {
            //                echo "transducer null\n";
            //                return;
            //            }
        }
    }
}
function printTransducer2($trandsucer)
{
    echo "transducer " .
        //                        $uuid .
        //                        " " .
        //                        $transducer_id .
        //                        " " .
        $transducer["sensor_id"] .
        " " .
        $transducer["talker_identifier"] .
        //                        "" .
        //                        $id .
        "" .
        $transducer["type"] .
        " " .
        $transducer["name"] .
        " " .
        $transducer["amount"] .
        " " .
        $transducer["units"] .
        "\n";
}

function custom_sort($a, $b)
{
    return $a["sensor_id"] > $b["sensor_id"];
}

function printVariable(
    $thing,
    $snapshot,
    $variable_name = null,
    $variable_units = null,
    $label = null
) {
    $variable_label = "no label";
    $variable_text = "no variable";
    $units_text = "";

    if ($label != null) {
        $label_text = $label;
    }
    if ($label == null) {
        $label_text = $variable_name;
    }

    if (isset($snapshot->{$variable_name})) {
        if (is_array($snapshot->{$variable_name})) {
            if (array_depth($snapshot->{$variable_name}) == 1) {
                $variable_text = implode(" ", $snapshot->{$variable_name});
            } else {
                $variable_text = "array";
            }
        } elseif (is_string($snapshot->{$variable_name})) {
            $variable_text = $snapshot->{$variable_name};
        } else {
            $variable_text = "object?";
        }
    }

    //    if ((isset($snapshot->{$variable_units})) and (!is_string($snapshot->{$variable_units}))) {
    //    $thing->console($label_text . ": " . "Not string" . "\n");
    //return;
    //    }

    if (isset($snapshot->{$variable_units})) {
        $units_text = $snapshot->{$variable_units};
    }
    //var_dump($label_text);
    //var_dump($variable_text);
    $thing->console($label_text . ": " . $variable_text . "\n");
    //if (isset($ship_handler->ship_thing->variable)) {
    //    print_r($ship_handler->ship_thing->variable);
    //}
}

function printVariables($thing, $ship_handler, $label = null)
{
    if (isset($ship_handler->ship_thing->variable)) {
        print_r($ship_handler->ship_thing->variable);
    }
}

/*

Read a string to determine what to include or exclude.

*/

function sizeObject($obj)
{
    $mem = memory_get_usage();
    $DB_tmp = clone $obj;
    $mem = memory_get_usage() - $mem;
    unset($DB_tmp);
    return $mem;
}

function printMemory($thing, $ship_handler)
{
    $t = sizeObject($thing);
    $s = sizeObject($ship_handler);

    /* Currently used memory */
    $mem_usage = memory_get_usage();

    /* Peak memory usage */
    $mem_peak = memory_get_peak_usage();
    echo "ship-thing memory current " . round($mem_usage / 1024) . "KB" . " ";
    echo "peak " . round($mem_peak / 1024) . "KB" . "\n";

    //    echo "thing " . round($t / 1024) . "KB" . "\n";
    //    echo "ship handler " . round($s / 1024) . "KB" . "\n";
}

function printShip($thing, $ship_handler)
{
    $thing->console("ship id " . $ship_id . "\n");
    $thing->console("ship_id: " . $ship_handler->ship_id . "\n");
    $thing->console("thing uuid " . $thing->uuid . "\n");

    $thing->console("thing from " . $thing->from . "\n");
    $thing->console(
        "ship thing nom_from: " . $ship_handler->ship_thing->from . "\n"
    );
}

