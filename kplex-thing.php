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

        $discord_message_period = 60 * 5;

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

        $tcp_flag = false;
        if ($tcp_flag === true) {
            echo "Start kplex TCP listener thing.\n";
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

        if (substr($ship_handler->thing_report["sms"], 0, 6) == "SHIP X") {
            $thing = new \Nrwtaylor\StackAgentThing\Thing(null);
            $thing->Create($from, $to, "new ship");
            echo "Created ship thing.";
            $ship_handler = new \Nrwtaylor\StackAgentThing\Ship($thing, "ship");
        }
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
        $write = null;
        $except = null;
        while (true) {
            //        while (socket_select($read, $write, $except, null)) {
            $udp_response = socket_select($read, $write, $except, null);
            $udp_packet = is_string($data = socket_read($socket, 5120));

            if ($tcp_flag) {
                $tcp_packet = ($buffer = fgets($fp, 4096)) !== false;
            }
            //Read received packets with a maximum size of 5120 bytes.
            //       while (is_string($data = socket_read($socket, 5120))) {
            if ($udp_packet) {
                //                echo $data;
                //                var_dump($data);
                //                echo "rnrn";
                if ($data == "") {
                    continue;
                }
                //            }
                //        }

                //        while (($buffer = fgets($fp, 4096)) !== false) {
                $buffer = $data;

                // Call the ship handler and have it read the NMEA string
                // It will generate a variable with the current ship state as read.
                $response = $ship_handler->readShip($buffer);

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
                }

                $datagram_stack = stack($datagram_stack, $buffer);

                $snapshot = $ship_handler->ship_thing->variables->snapshot;

                //                outputVariable($thing, $snapshot);
                //                printStack($thing, $datagram_stack);
                $ship_handler->set();

                if (microtime(true) - $microtime_display > 1.0) {
                    $thing->console("Ship ID: " . $ship_id . "\n");
                    $thing->console(
                        "ship_id: " . $ship_handler->ship_id . "\n"
                    );
                    $thing->console("thing uuid: " . $thing->uuid . "\n");

                    $thing->console("thing from: " . $thing->from . "\n");
                    $thing->console(
                        "ship thing nom_from: " .
                            $ship_handler->ship_thing->from .
                            "\n"
                    );

                    $thing->console("Last message: " . $sms . "\n");
                    //$thing->console("Last set time: " . $last_set_time . "\n");
                    $thing->console(
                        "Last response: " . $ship_handler->response . "\n"
                    );
                    $thing->console(
                        "Thing UUID: " . $ship_handler->uuid . "\n"
                    );

                    $thing->console(
                        "Unrecognized sentences: " .
                            implode(" ", $unrecognized_sentences) .
                            "\n"
                    );

                    outputVariable($thing, $snapshot);
                    printStack($thing, $datagram_stack);

                    $microtime_display = microtime(true);
                }

                //if (!isset($snapshot_master)) {$snapshot_master = $snapshot;}

                $array_snapshot = json_decode(json_encode($snapshot), true);
                //if (!isset($snapshot_master)) {$snapshot_master = $snapshot;}

                if (!isset($snapshot_master)) {
                    $snapshot_master = $array_snapshot;
                }

                //array_merge_recursive_simple($snapshot_master, $array_snapshot);
                //array_merge_recursive_ex($snapshot_master, $array_snapshot);

                //$snapshot_master = drupal_array_merge_deep_array([$snapshot_master, $array_snapshot]);

                $snapshot_master = $array_snapshot;

$json = json_encode($snapshot_master);
$bytes = file_put_contents("/var/www/kplex-thing/snapshot.json", $json); 

                if (microtime(true) - $microtime_log > $discord_message_period) {
                    // Dev Log with mongo/express stack.

                    // Send input to stack express node server.
                    $transducers = $snapshot_master["transducers"];
//var_dump($snapshot_master);
$fix_sms = "FIX ";
    $fix_sms .= "Time " . $snapshot_master['fix_time'] . " ";
//exit();
    $fix_sms .= "Timestamp " . $snapshot_master['time_stamp'] . " ";
    $fix_sms .= "Datestamp " . $snapshot_master['date_stamp'] . " ";
    $fix_sms .= "Quality " . $snapshot_master['fix_quality'] . " ";
    $fix_sms .= "Latitude " . $snapshot_master['current_latitude_decimal'] . " ";
    $fix_sms .= "Longitude " . $snapshot_master['current_longitude_decimal'];

                    $m = "TRANSDUCERS ";
                    foreach ($transducers as $i => $j) {
                        //$m .= " " . $i . $j['name'] . " " . $j['amount'];
                        $m .= $j["name"] . " " . $j["amount"] . " ";
                    }

                    $discord_handler = new \Nrwtaylor\StackAgentThing\Discord(
                        null,
                        "discord"
                    );
                    $discord_handler->sendDiscord(
                        $m,
                        "kokopelli:#general@kaiju.discord"
                    );

                    $discord_handler->sendDiscord(
                        $fix_sms,
                        "kokopelli:#general@kaiju.discord"
                    );

                    //exit();
                    //var_dump("sendDiscord instruction sent");
                    $array = ["merp" => "merp"];
                    $response = file_get_contents(
                        "http://192.168.10.10/api/whitefox/" . $buffer
                    );
                    //$response = file_get_contents("http://localhost:3001/" . $buffer);
                    //var_dump($response);
                    $data_thing = new \Nrwtaylor\StackAgentThing\Thing(null);
                    $data_thing->Create("kokopelli iv", "datalog", "log");

                    //$discord_handler = new \Nrwtaylor\StackAgentThing\Discord($data_thing,"discord");
                    //$discord_handler->sendDiscord('kokopelli:#general@kaiju.discord','Test');

                    $data_thing->json->setField("variables");
                    $data_thing->json->writeVariable(
                        ["snapshot"],
                        $snapshot_master
                    );




                    $microtime_log = microtime(true);
                }
            }
        }

        if (!feof($fp)) {
            echo "Error: unexpected fgets() fail\n";
        }
        fclose($fp);

        exit();

        if (isset($thing->thing) and $thing->thing != false) {
            $f = trim(str_replace($uuid, "", $input));
            if ($f == "" or $f == "agent") {
                $agent = new Uuid($thing, $f);
                $this->thing_report = $agent->thing_report;
                return;
            }
            $agent = new Agent($thing, $f);

            $this->thing_report = $agent->thing_report;
            return;
        }

        $thing->Create($from, $to, $message, $agent_input);

        // Tag as console input
        $console = new \Nrwtaylor\StackAgentThing\Channel($thing, "console");

        // Get the handler which takes the short message.
        // e.g. Gearman, direct PHP call, Apache request ...

        $handler = strtolower($input->getOption("handler"));

        switch ($handler) {
            case "gearman":
                // Build, send and receive the Gearman datagram.
                $arr = json_encode([
                    "to" => $from,
                    "from" => "agent",
                    "subject" => $message,
                    "agent_input" => $agent_input,
                ]);
                $client = new \GearmanClient();
                $client->addServer();
                $thing_string = $client->doNormal("call_agent", $arr);

                // To reduce load Gearman can handle calls in the background.
                // $client->doHighBackground("call_agent", $arr);

                if ($thing_string == "") {
                    // TODO: Handle null strings from Gearman.
                    // For now echo to console.
                    echo "Null string returned from GEARMAN\n";
                }

                $thing_report = json_decode($thing_string, true);
                break;
            default:
                // Default console handler is SMS.
                $handler = $default_handler;
                $agent = new \Nrwtaylor\StackAgentThing\Agent(
                    $thing,
                    $agent_input
                );

                $thing_report = $agent->thing_report;
        }

        $response = "";

        $response .= responseLog($log, $thing, $thing_report);

        // See handling command line options.
        // https://symfony.com/doc/current/console/input.html
        $channel = $input->getOption("channel");

        if ($channel == false) {
            $channel = $default_channel;
        }

        $text_response = "No text response.";
        if (isset($thing_report[$channel])) {
            $text_response = $thing_report[$channel];

            if ($channel == "log") {
                $text_response = preg_replace(
                    "#<br\s*/?>#i",
                    "\n",
                    $text_response
                );
            }
        }

        $response .= $text_response;

        $channels = strtolower($input->getOption("show-channels"));

        if ($channels == true) {
            $channels_text = "";
            foreach ($thing_report as $channel => $value) {
                $channels_text .= $channel . " ";
            }
            $response .= "\n" . trim($channels_text);
        }

        $urls = strtolower($input->getOption("show-urls"));
        $urls_text = "";
        if ($urls == true) {
            if (isset($agent->link)) {
                $urls_text .= $agent->link . "\n";
            }
            if (isset($agent->url)) {
                $urls_text .= $agent->url . "\n";
            }
            if (isset($agent->urls)) {
                $urls_text .= implode("\n", $agent->urls) . "\n";
            }
            $response .= "\n" . trim($urls_text);
        }

        $text_handler = new \Nrwtaylor\StackAgentThing\Text($thing, "text");

        $query_handler = new \Nrwtaylor\StackAgentThing\Query($thing, "query");

        if ($watch !== false) {
            [$log_includes, $log_excludes] = $query_handler->parseQuery($watch);

            $watch_flag = $text_handler->filterText(
                $text_response,
                $log_includes,
                $log_excludes
            );
            if ($watch_flag !== true) {
                //$error_code = 1;
                $flag_match = 0; // Found a match
            }
        }

        if ($regex !== false) {
            $pattern = $regex;
            $regex_flag = preg_match($pattern, $text_response);
            if ($regex_flag === 0) {
                $regex_flag = true;
            } else {
                $regex_flag = false;
            }
            if ($regex_flag !== true) {
                //$error_code = 1;
                $flag_match = 0; // Found a match.
            }
        }

        /*
Claws options to test:  "0 (passed)", "non-0 (failed)"
So in this content. "Failed to find a match" is 1.
*/

        $meta = strtolower($input->getOption("meta"));

        if ($meta == false) {
            $meta = $default_meta;
        }

        if ($meta == "stack" or $meta == "on") {
            $meta_response = "";
            $agentclock = new \Nrwtaylor\StackAgentThing\Clocktime(
                $thing,
                "clocktime"
            );

            $meta_response .=
                strtoupper($handler) .
                " " .
                number_format($thing->elapsed_runtime()) .
                "ms";
            $meta_response .= " " . $from;
            $agentclock->makeClocktime();
            $meta_response .=
                "\n" . $agentclock->clock_time . " " . $thing->nuuid;

            $prior = new \Nrwtaylor\StackAgentThing\Prior($thing, "prior");
            $meta_response .= " " . substr($prior->prior_uuid, 0, 4);

            if (isset($watch_flag) and $watch_flag !== true) {
                $meta_response .= " WATCH FLAG";
            }

            if (
                (isset($regex_flag) or isset($watch_flag)) and
                $regex_flag !== true
            ) {
                $meta_response .= $regex_error == "" ? "" : " " . $regex_error;
            }
            if (isset($regex_flag) and $regex_flag !== true) {
                $meta_response .= " REGEX FLAG";
            }

            // Determine responsiveness.
            // Did the stack provide a thing, a thing and a response ...
            // Did the stack respond?
            $stack_text = "No stack response.";
            if (isset($thing_report)) {
                if ($prior->prior_uuid == false) {
                    $stack_text = "Persistent stack not found.";
                } else {
                    $stack_text = "Memory available.";
                }
                if (
                    isset($thing_report["thing"]) and
                    $thing_report["thing"] == false
                ) {
                    $stack_text = "No thing provided in response.";
                }

                if (isset($thing_report["thing"]->from)) {
                    $stack_text = "Added to stack.";

                    if ($thing_report["thing"]->from == null) {
                        $stack_text = "Null stack.";
                    }
                }
            }

            $meta_response .= " " . $stack_text;
        }

        $output->writeln("<info>$response</info>");

        if (isset($meta_response)) {
            $output->writeln("<comment>$meta_response</comment>");
        }
        // If error flagging is on, then return the generated error code.
        // Claws uses this with a test filter
        // And it is a common way of returning a signal from a
        // shell or perl script.

        // Use --flag-error to request this.

        $flag_error = strtolower($input->getOption("flag-error"));
        if ($flag_error == true) {
            return $flag_match; //0 --- match found, 1 --- failed to find match
            //return $error_code;
        }

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

function stack($datagram_stack, $datagram)
{
    $max = 10;
    if (!isset($datagram_stack)) {
        $datagram_stack = [];
    }

    $stack_height = count($datagram_stack);

    if ($stack_height > $max) {
        array_splice($datagram_stack, 0, $stack_height - $max);
    }

    $datagram_stack[] = $datagram;
    return $datagram_stack;
}

function printStack($thing, $datagram_stack)
{
    foreach ($datagram_stack as $i => $datagram) {
        $thing->console(trim($datagram) . "\r\n");
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
        printTransducer($transducer);
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
        //    $thing->transducers = $filtered_transducers;

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
        //var_dump($transducer);
        //$thing->transducers[$id] = $transducer;
    }

    //$thing->transducers = [];
    //$thing->transducers = $filtered_transducers;
    //$thing->transducers =[];

    foreach ($snapshot as $key => $value) {
        //var_dump($key);
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

