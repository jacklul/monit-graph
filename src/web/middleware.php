<?php

// Application middleware
if (isset(\MonitGraph\Base::config()['basic_auth_users'])) {
    $app->add(new \Tuupola\Middleware\HttpBasicAuthentication([
      "users" => \MonitGraph\Base::config()['basic_auth_users']
    ]));
}
