<?php

if (!file_exists(__DIR__ . "/servers.ini")) {
    die("Please set config/servers.ini first");
}

return [
    /* Monit servers */
    'server_configs' => parse_ini_file(__DIR__ . "/servers.ini", true),

    /* Monit-Graph display information */
    'default_chart_type' => "LineChart", // Default chart type
    'default_time_range' => 3600 * 12, // Amount in seconds of the default view should be (0 equals all available data)
    'default_refresh_seconds' => 120, // Default amount of seconds before data is reloaded (0 equals never)
    'default_dont_show_alerts' => "on", // By default don't show alerts on the graphs
    'default_specific_service' => "", // Default service to be displayed (none is equal to all services)
    'limit_records_shown' => 1440, // Maximum number of 'dots' on the graph (1440 is full day of 1-minute intervals)

    /* Monit-Graph history handling */
    'chunk_size' => 1024 * 1024, // [XML] Maximum size in bytes for each service history chunk (0 equals unlimited, remember to set php.ini so the scripts can handle it as well)
    'limit_number_of_chunks' => 10, // [XML] Maximum number of chunks saved per service records, will delete all above this (0 equals unlimited)
    'use_sqlite' => true, // Use SQLite database instead of XML files
    'max_record_age' => 3600 * 24 * 31, // [SQLite] Maximum age of a database record before it is removed (0 equals never)

    /* Monit-Graph data import filtering */
    //'only_service_types' => [3, 5],
    // 0 = filesystem, 1 = directory*, 2 = file,
    // 3 = process, 4 = host*, 5 = system,
    // 6 = fifo*, 7 = program, 8 = network
    // * - nothing specific is logged, only basic monit status

    /* Enable deletion of the data from the interface */
    //'allow_delete' => true,

    /* Basic authentication */
    //'basic_auth_users' => ['admin' => getenv('ADMIN_PASSWORD')] // By default, ADMIN_PASSWORD environment variables is used
    // You can generate your own hashed password by running `htpasswd -nbBC 10 username password`

    /* Override data directory */
    //'data_dir' => "/var/lib/monit-graph/",

    /* Slim configuration */
    'slimconfig' => [
        'displayErrorDetails' => true, // Set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header
        'logger' => [
            'path' => '' // Empty means log to error_log() function
        ]
    ],
];
