<?php
/**
 * Monit Graph
 *
 * Copyright (c) 2011, Dan Schultzer <http://abcel-online.com/>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Dan Schultzer nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL DAN SCHULTZER BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @package monit-graph
 * @author Dan Schultzer <http://abcel-online.com/>
 * @copyright Dan Schultzer
 */

namespace MonitGraph;

/**
 * Monit Graph base class
 *
*/
class Base
{
    /**
     * Version of MonitGraph
     */
    const string VERSION = '3.0';

    /**
     * Identifier of MonitGraph
     */
    const string IDENTIFIER = 'MonitGraph';

    /**
     * Path to data directory
     */
    const string DATA_PATH = __DIR__ . '/../../data';

    /**
     * Path to data directory
     */
    const string SERVER_XML_FILE_NAME = 'server.xml';

    /**
     * Monitored service types by default
     */
    const array MONITORED_SERVICE_TYPES = [3, 5]; // Process, System

    /**
     * Configs
     */
    public static function config(): array
    {
        if (getenv("CONFIG_FILE")) {
            $config = require(getenv("CONFIG_FILE"));
        } elseif (file_exists(__DIR__ . "/../../config/config.php")) {
            $config = require(__DIR__ . "/../../config/config.php");
        } else {
            $config = require(__DIR__ . "/../../config/config.default.php");
        }

        return $config;
    }

        /**
     * Get data directory
     */
    public static function dataDir(): string
    {
        if (isset(self::config()['data_dir'])) {
            return self::config()['data_dir'] . "/";
        }

        return self::DATA_PATH . "/";
    }

    /**
     * Testing the server configs
     */
    public static function checkConfig(array $server_configs): bool
    {
        $id = [];
        $url = [];

        foreach ($server_configs as $config) {
            $id[] = $config['server_id'];
            $url[] = $config['config']['url'];
        }

        if (count($id) != count(array_unique($id))) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": ID's in server config needs to be unique");
            return false;
        }

        if (count($url) != count(array_unique($url))) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": You should not use the same URL for individual servers");
            return false;
        }

        return true;
    }

    /**
     * Running cron
     *
     * Will connect and download data from the monit URL.
     */
    public static function cron(
        string $server_id,
        string $monit_url,
        string $monit_uri_xml,
        bool $monit_url_ssl = true,
        string $monit_http_username = "",
        string $monit_http_password = "",
        bool $verify_ssl = true
    ): void {
        $found_settings = $http_login = false;

        if (!($server_id = self::isServerIDValid($server_id))) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Server ID invalid");
            exit(1);
        }

        $xml = self::getSettings($server_id);

        if ($xml != false) {
            $found_settings = true;
        } // Do we already have the settings file saved or is this fresh version?

        if (strlen($monit_http_username)>0) {
            $http_login = true;
        } // If username are used, http login has to be done

        if ($found_settings) {
            $time_difference = intval($xml->incarnation) + intval($xml->uptime) + intval($xml->poll) - 20 - time(); // 20 seconds connection time
            if ($time_difference > 0) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Poll time has not been reached (missing $time_difference seconds), waiting for one more cycle");
                return;
            }
        }

        if ($monit_url_ssl) {
            $url = "https://";
        } else {
            $url = "http://";
        }

        $url .= $monit_url . "/" . $monit_uri_xml;
        $curl = new \Curl\Curl();
        $curl->setHeader('Accept', 'application/xml');
        $curl->setUserAgent('Cron Monit Graph ' . self::VERSION);

        if ($monit_url_ssl) {
            $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
            if ($verify_ssl) {
                $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
            } else {
                $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 0);
            }
        }
        $curl->setOpt(CURLOPT_TIMEOUT, 20);
        $curl->setOpt(CURLOPT_RETURNTRANSFER, true);

        if ($http_login) {
            $curl->setOpt(CURLOPT_HTTPAUTH, CURLAUTH_BASIC) ;
            $curl->setOpt(CURLOPT_USERPWD, $monit_http_username . ":" . $monit_http_password) ;
        }

        $curl->get($url);

        if ($curl->error) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": cURL Error ($curl->error_code): $curl->error");
        } else {
            libxml_use_internal_errors(true);

            if ($xml = simplexml_load_string($curl->response)) {
                if (isset($xml->server)) {
                    if (self::putSettings($server_id, $xml->server)) {
                        $use_sqlite = self::config()['use_sqlite'] ?? false; // fallback - use XML files

                        if ($use_sqlite) {
                            $pdo = self::openDatabase($server_id, false);
                        } else {
                            $chunk_size = self::config()['chunk_size'] ?? 0; // fallback - no limit
                            $number_of_chunks = self::config()['limit_number_of_chunks'] ?? 0; // fallback - no limit
                        }

                        $allowed_service_types = self::config()['only_service_types'] ?? self::MONITORED_SERVICE_TYPES; // fallback - only useful ones

                        foreach ($xml->service as $service) {
                            if (in_array((int) $service["type"], $allowed_service_types)) {
                                if ($use_sqlite) {
                                    /** @var \PDO $pdo */
                                    self::insertDatabaseRecord($pdo, $service, (int) $service["type"]);
                                } else {
                                    self::writeServiceHistoric($server_id, $service, $service["type"], $chunk_size, $number_of_chunks);
                                }
                            }
                        }

                        if ($use_sqlite) {
                            $max_record_age = self::config()['max_record_age'] ?? 0; // fallback - no limit - no cleanup

                            if ($max_record_age > 0) {
                                $time = $xml->server->incarnation + $xml->server->uptime;
                                /** @var \PDO $pdo */
                                self::deleteOldDatabaseRecords($pdo, $time, $max_record_age);
                            }
                        }
                    }
                }
            } else {
                foreach (libxml_get_errors() as $error) {
                    error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": " . $error->message);
                }
            }
        }
    }

    /**
     * Return false or the simplexml object depending if the settings file exists
     */
    public static function getSettings(int $server_id): \SimpleXMLElement|false
    {
        if (!self::settingsWriteable($server_id)) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write settings");
            exit(1);
        }

        $filename = self::dataDir() . $server_id . "-" . self::SERVER_XML_FILE_NAME;
        if (file_exists($filename)) {
            return simplexml_load_string(file_get_contents($filename));
        }

        return false;
    }

    /**
     * Save the settings file from simplexml object to DOM
     */
    public static function putSettings(int $server_id, \SimpleXMLElement $xml): bool
    {
        if (!self::settingsWriteable($server_id)) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write settings");
            exit(1);
        }

        $filename = self::dataDir() . $server_id . "-" . self::SERVER_XML_FILE_NAME;
        if (!$handle = fopen($filename, 'w')) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot open $filename");
            exit(1);
        }

        $dom_xml = dom_import_simplexml($xml);

        $dom = new \DOMDocument('1.0');
        $dom_xml = $dom->importNode($dom_xml, true);
        $dom_xml = $dom->appendChild($dom_xml);
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (fwrite($handle, $dom->saveXML()) === false) {
            fclose($handle);
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write to $filename");
            exit(1);
        }

        fclose($handle);

        return true;
    }

    /**
     * Return true or false if the data path is writeable
     */
    public static function datapathWriteable(): bool
    {
        if (!is_writeable(self::dataDir())) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": " . self::dataDir() . " is not write-able!");
            return false;
        }

        return true;
    }

    /**
     * Return true or false if the settings file is writeable
     */
    public static function settingsWriteable(int $server_id): bool
    {
        if (!self::datapathWriteable()) {
            return false;
        }

        $filename = self::dataDir() . $server_id . "-" . self::SERVER_XML_FILE_NAME;
        if (file_exists($filename) && !is_writeable($filename)) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": " . $filename . " is not write-able!");
            return false;
        }

        return true;
    }

    /**
     * Open database and return PDO handle
     */
    public static function openDatabase(int $server_id, bool $readonly = false): \PDO
    {
        if (!self::datapathWriteable() && !$readonly) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write in data path");
            exit(1);
        }

        $db_file = self::dataDir() . $server_id . "-server.db";

        if (!file_exists($db_file) && $readonly) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database file does not exist for server ID $server_id");
            exit(1);
        }

        try {
            $pdo = new \PDO('sqlite:file:' . $db_file . ($readonly ? '?mode=ro&immutable=1' : ''));
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
            $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);

            try {
                $stmt = $pdo->prepare("SELECT value FROM properties WHERE variable = 'version'");
                $stmt->execute();
                $version = (int) ($stmt->fetchColumn() ?: 0);
            } catch (\PDOException $e) {
                $version = 0;
            }

            if (!$readonly) {
                $migrations = scandir(__DIR__ . '/../db');

                foreach ($migrations as $migration) {
                    if (preg_match('/^(\d+)_to_(\d+)\.sql$/', $migration, $matches)) {
                        $from_version = (int) $matches[1];
                        $to_version = (int) $matches[2];

                        if ($version === $from_version) {
                            $migration_sql = file_get_contents(__DIR__ . '/../db/' . $migration);
                            $pdo->exec($migration_sql);
                            $pdo->prepare("INSERT INTO properties (variable, value) VALUES (?, ?) ON CONFLICT(variable) DO UPDATE SET value = excluded.value")->execute(['version', $to_version]);
                            $version = $to_version;
                        }
                    }
                }
            }
        } catch (\PDOException $e) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
            exit(1);
        }

        return $pdo;
    }

    /**
     * Will write the XML history file for a specific service. Inputs simplexml object and service type
     */
    public static function writeServiceHistoric(int $server_id, \SimpleXMLElement $xml, string $type, int $chunk_size = 0, int $number_of_chunks = 0): void
    {
        $type_int = intval($type);

        if ($type_int >= 0 || $type_int <= 8) {
            $name = $xml->name;

            if (!self::datapathWriteable()) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write in data path");
                exit(1);
            }

            $dom = new \DOMDocument('1.0');
            $service = $dom->createElement("records");
            $attr_name = $dom->createAttribute("name");
            $attr_name->value = $name;
            $service->appendChild($attr_name);
            $dom->appendChild($service);

            $attr_type = $dom->createAttribute("type");
            $attr_type->value = $type;
            $service->appendChild($attr_type);

            $new_service = $dom->createElement("record");
            $time = $dom->createAttribute("time");
            $time->value = $xml->collected_sec;
            $new_service->appendChild($time);

            switch ($type_int) {
                case 0: // Filesystem
                    $usage = $dom->createElement("usage", self::getMonitPercentage($xml->block));
                    $new_service->appendChild($usage);
                    break;
                case 1: // Directory - nothing to store
                    break;
                case 2: // File
                    $size = $dom->createElement("size", $xml->size);
                    $new_service->appendChild($size);
                    break;
                case 3: // Process
                    $cpu = $dom->createElement("cpu", self::getMonitPercentage($xml->cpu));
                    $new_service->appendChild($cpu);
                    $memory = $dom->createElement("memory", self::getMonitPercentage($xml->memory));
                    $new_service->appendChild($memory);
                    //$pid = $dom->createElement("pid", $xml->pid);
                    //$new_service->appendChild($pid);
                    //$uptime = $dom->createElement("uptime", $xml->uptime);
                    //$new_service->appendChild($uptime);
                    //$children = $dom->createElement("children", $xml->children);
                    //$new_service->appendChild($children);
                    break;
                case 4: // Host - nothing to store
                    break;
                case 5: // System
                    $cpu = $dom->createElement("cpu", self::combineChildrenValues($xml->system->cpu));
                    $new_service->appendChild($cpu);
                    $memory = $dom->createElement("memory", self::getMonitPercentage($xml->system->memory));
                    $new_service->appendChild($memory);
                    $swap = $dom->createElement("swap", self::getMonitPercentage($xml->system->swap));
                    $new_service->appendChild($swap);
                    break;
                case 6: // FIFO - nothing to store
                    break;
                case 7: // Program
                    $code = $dom->createElement("code", $xml->program->status);
                    $new_service->appendChild($code);
                    break;
                case 8: // Network
                    $download = $dom->createElement("download", $xml->link->download->bytes->now);
                    $new_service->appendChild($download);
                    $upload = $dom->createElement("upload", $xml->link->upload->bytes->now);
                    $new_service->appendChild($upload);
                    break;
            }

            $alert = $dom->createElement("alert", intval($xml->status > 0));
            $new_service->appendChild($alert);

            $status = $dom->createElement("status", $xml->status);
            $new_service->appendChild($status);

            $monitor = $dom->createElement("monitor", $xml->monitor);
            $new_service->appendChild($monitor);

            $service->appendChild($new_service);

            $dom->validate();

            $dir = self::dataDir() . $server_id;
            if (!is_dir($dir)) {
                if (!mkdir($dir)) {
                    error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Could not create data path $dir");
                    exit(1);
                }
            }

            $filename = $dir . "/" . $name . ".xml";

            if (file_exists($filename)) {
                if (!self::rotateFiles($filename, $chunk_size, $number_of_chunks)) {
                    error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Fatal error, could not rotate file $filename");
                    exit(1);
                }

                /* Load in the previous xml */
                if (file_exists($filename) && $existing_xml = simplexml_load_string(file_get_contents($filename))) {
                    $dom_xml = dom_import_simplexml($existing_xml);

                    foreach ($dom_xml->childNodes as $node) {
                        // Do not insert duplicated timestamps
                        if ($node instanceof \DOMElement && $node->nodeName === "record" && $node->hasAttribute("time") && (string) $node->getAttribute("time") === (string) $xml->collected_sec) {
                            return;
                        }

                        $node = $dom->importNode($node, true);
                        $node = $service->appendChild($node);
                    }
                }
            }

            if (!$handle = fopen($filename, 'w')) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot open $filename");
                exit(1);
            }

            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;

            if (fwrite($handle, $dom->saveXML()) === false) {
                fclose($handle);
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Cannot write to $filename");
                exit(1);
            }

            fclose($handle);
        }
    }

    /**
     * Will write database record for specific service type
     */
    public static function insertDatabaseRecord(\PDO $pdo, \SimpleXMLElement $xml, int $type): bool
    {
        if ($type >= 0 || $type <= 8) {
            $name = $xml->name;
            $time = $xml->collected_sec;
            $alert = intval($xml->status > 0);
            $status = $xml->status;
            $monitor = $xml->monitor;

            $default_fields = ['name', 'time', 'monitor', 'status', 'alert'];
            $default_values = [$name, $time, $monitor, $status, $alert];
            $fields = [];
            $values = [];

            try {
                $pdo->prepare("INSERT OR IGNORE INTO service_type (name, type) VALUES (?, ?)")->execute([$name, $type]);

                switch ($type) {
                    case 0: // Filesystem
                        $fields = ['usage'];
                        $values = [self::getMonitPercentage($xml->block)];
                        break;
                    case 1: // Directory - nothing to store
                        break;
                    case 2: // File
                        $fields = ['size'];
                        $values = [$xml->size];
                        break;
                    case 3: // Process
                        $fields = ['cpu', 'memory'];
                        $values = [self::getMonitPercentage($xml->cpu), self::getMonitPercentage($xml->memory)];
                        break;
                    case 4: // Host - nothing to store
                        break;
                    case 5: // System
                        $fields = ['cpu', 'memory', 'swap'];
                        $values = [self::combineChildrenValues($xml->system->cpu), self::getMonitPercentage($xml->system->memory), self::getMonitPercentage($xml->system->swap)];
                        break;
                    case 6: // FIFO - nothing to store
                        break;
                    case 7: // Program
                        $fields = ['code'];
                        $values = [$xml->program->status];
                        break;
                    case 8: // Network
                        $fields = ['download', 'upload'];
                        $values = [$xml->link->download->bytes->now, $xml->link->upload->bytes->now];
                        break;
                }

                $fields_sql = '';
                $values_sql = '';
                foreach (array_merge($default_fields, $fields) as $field) {
                    !empty($fields_sql) && $fields_sql .= ', ';
                    !empty($values_sql) && $values_sql .= ', ';
                    $fields_sql .= $field;
                    $values_sql .= '?';
                }

                $stmt = $pdo->prepare("INSERT OR IGNORE INTO service_$type ($fields_sql) VALUES ($values_sql)");
                $stmt->execute(array_merge($default_values, $values));
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        }

        return true;
    }

    /**
     * Will return a Google Graph JSON string or false
     */
    public static function returnGoogleGraphJSON(string $filename, int $time_range, int $limit_number_of_items = 0): string|false
    {
        $use_sqlite = self::config()['use_sqlite'] ?? false;

        if ($use_sqlite) {
            $file = explode('/', $filename);
            $server_id = $file[count($file) - 2] ?? null;
            $service_name = $file[count($file) - 1] ?? null;
            $service_name = preg_replace('/\.xml$/', '', $service_name);

            if (!$server_id || !$service_name) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Unable to extract server ID or service name from filename: $filename");
                return false;
            }

            try {
                $pdo = self::openDatabase((int) $server_id, true);

                $stmt = $pdo->prepare("SELECT type FROM service_type WHERE name = ?");
                $stmt->execute([$service_name]);

                $type = $stmt->fetchColumn();
                if (!is_numeric($type)) {
                    error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Unable to obtain type value ($type) for service name: $service_name");
                    return false;
                }
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        } else {
            if (!file_exists($filename) or !$xml = simplexml_load_string(file_get_contents($filename))) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": $filename could not be found!");
                return false;
            }

            $type = (int) $xml["type"];
        }

        $array = [];
        switch ($type) {
            case 0:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "Usage", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            case 2:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "Size", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            case 3:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "CPU", "type" => "number"],
                    ["label" => "Memory", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            case 5:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "CPU", "type" => "number"],
                    ["label" => "Memory", "type" => "number"],
                    ["label" => "Swap", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            case 7:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "Status", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            case 8:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "Download", "type" => "number"],
                    ["label" => "Upload", "type" => "number"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
            default:
                $array["cols"] = [
                    ["label" => "Time", "type" => "datetime"],
                    ["label" => "Alert", "type" => "number"]
                ];
                break;
        }
        $array["rows"] = [];

        $allowed_memory = self::letToNum(ini_get('memory_limit'));

        ini_set('serialize_precision', -1); // to avoid long float numbers in JSON output

        if ($use_sqlite) {
            /** @var \PDO $pdo */
            /** @var string $service_name */
            try {
                if ($time_range > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM service_$type WHERE name = ? AND time >= ? ORDER BY time DESC");
                    $stmt->execute([$service_name, time() - $time_range]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM service_$type WHERE name = ? ORDER BY time DESC");
                    $stmt->execute([$service_name]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                }

                foreach ($rows as $row) {
                    $rowData = [["v" => "%%new Date(" . ((int) $row['time'] * 1000) . ")%%"]];

                    switch ($type) {
                        case 0:
                            $rowData[] = ["v" => self::formatFloat($row['usage'])];
                            break;
                        case 2:
                            $rowData[] = ["v" => (int) $row['size']];
                            break;
                        case 3:
                            $rowData[] = ["v" => self::formatFloat($row['cpu'])];
                            $rowData[] = ["v" => self::formatFloat($row['memory'])];
                            break;
                        case 5:
                            $rowData[] = ["v" => self::formatFloat($row['cpu'])];
                            $rowData[] = ["v" => self::formatFloat($row['memory'])];
                            $rowData[] = ["v" => self::formatFloat($row['swap'])];
                            break;
                        case 7:
                            $rowData[] = ["v" => (int) $row['code']];
                            break;
                        case 8:
                            $rowData[] = ["v" => (int) $row['download']];
                            $rowData[] = ["v" => (int) $row['upload']];
                            break;
                    }

                    $rowData[] = ["v" => (int) $row['alert'] * 100];
                    $array["rows"][]["c"] = $rowData;

                    if ((memory_get_usage() / $allowed_memory) > 0.9) {
                        error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Memory usage is using over 90% (of $allowed_memory) with currently " . count($array["rows"]) . " rows (last record with date of " . date("Y-m-d H:i:s P", intval($row['time'])) . "). Please increase allowed memory use if you wish parse more data.");
                        break;
                    }
                }
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        } else {
            if (!file_exists($filename) or !$xml = simplexml_load_string(file_get_contents($filename))) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": $filename could not be found!");
                return false;
            }

            $include_file_number = 0;
            $run_while = true;

            while ($run_while) {
                /* We run through each record to built the JSON */
                foreach ($xml->record as $record) {
                    if ($time_range > 0 && $record['time'] < (time() - $time_range)) {
                        break;
                    } // Stop with data if we have reached the time range

                    $recordData = [["v" => "%%new Date(" . (intval($record['time']) * 1000) . ")%%"]];

                    /* Different setup for different service types */
                    switch ($type) {
                        case 0:
                            $rowData[] = ["v" => self::formatFloat((float) $record->usage)];
                            break;
                        case 2:
                            $rowData[] = ["v" => (int) $record->size];
                            break;
                        case 3:
                            $rowData[] = ["v" => self::formatFloat((float) $record->cpu)];
                            $rowData[] = ["v" => self::formatFloat((float) $record->memory)];
                            break;
                        case 5:
                            $rowData[] = ["v" => self::formatFloat((float) $record->cpu)];
                            $rowData[] = ["v" => self::formatFloat((float) $record->memory)];
                            $rowData[] = ["v" => self::formatFloat((float) $record->swap)];
                            break;
                        case 7:
                            $rowData[] = ["v" => (int) $record->code];
                            break;
                        case 8:
                            $rowData[] = ["v" => (int) $record->download];
                            $rowData[] = ["v" => (int) $record->upload];
                            break;
                    }

                    $recordData[] = ["v" => (int) $record->alert * 100];
                    $array["rows"][]["c"] = $recordData;

                    /* Just checking if we reach memory limit and stop when that happens */
                    if ((memory_get_usage()/$allowed_memory)>0.9) {
                        error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Memory usage is using over 90% (of $allowed_memory) with currently " . count($array["rows"]) . " rows (last record with date of " . date("Y-m-d H:i:s P", intval($record['time'])) . "). Please increase allowed memory use if you wish parse more data.");
                        $run_while = false;
                        break;
                    }
                }

                /* We check if the next file exists, and load the simplexml object if so */
                $next_file = $filename . "." . (string) $include_file_number;

                if (file_exists($next_file)) {
                    $xml = null;
                    unset($xml);

                    if (!$xml = simplexml_load_string(file_get_contents($next_file))) {
                        error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": " . $next_file . " could not be opened");
                        break;
                    }
                } else {
                    break; // No other files, this is all the data we can get
                }

                $include_file_number++;
            }
        }

        /* Reversing the array, so oldest historic is first */
        $array["rows"] = array_reverse($array["rows"]);

        /* We don't want to pass too much information, let's keep it under the limit */
        $number_of_items = count($array["rows"]);
        if ($limit_number_of_items > 0 && $number_of_items > $limit_number_of_items) {
            $exponent = ceil(log($limit_number_of_items/$number_of_items)/log(0.5)); // Calculating how many iterations we should do until we are below the maximum number of items
            for ($i = 0; $i < $exponent; $i++) {
                foreach (range(1, count($array["rows"]), 2) as $key) { // Go through every second element and delete it
                    unset($array["rows"][$key]);
                }
                $array["rows"] = array_merge($array["rows"]); // Redo index
            }
        }

        /* JSON encode and enable javascript function */
        $json = json_encode($array);

        return $json;
    }

    /**
     * Will return a Google Graph JSON string or false
     */
    public static function getLastRecord(int $server_id): array|false
    {
        $use_sqlite = self::config()['use_sqlite'] ?? false;

        if ($use_sqlite) {
            try {
                $pdo = self::openDatabase($server_id, true);

                $stmt = $pdo->prepare("SELECT name, type FROM service_type");
                $stmt->execute();
                $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                usort($services, fn($a, $b) => strnatcmp($a['name'], $b['name']));

                $return_array = [];
                foreach ($services as $service) {
                    $type = (int) $service['type'];
                    $name = $service['name'];

                    $stmt = $pdo->prepare("SELECT * FROM service_$type WHERE name = ? ORDER BY time DESC LIMIT 1");
                    $stmt->execute([$name]);
                    $record = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($record) {
                        $return_array[] = [
                            "name" => $name,
                            "time" => intval($record['time']),
                            "memory" => isset($record['memory']) ? self::formatFloat($record['memory']) : null,
                            "cpu" => isset($record['cpu']) ? self::formatFloat($record['cpu']) : null,
                            "swap" => isset($record['swap']) ? self::formatFloat($record['swap']) : null,
                            "status" => isset($record['status']) ? self::formatFloat($record['status']) : null
                        ];
                    }
                }

                return $return_array;
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        }

        $files = self::getLogFilesForServerID($server_id);

        if (!$files) {
            return false;
        }

        /* Check the directory for the Monit instance ID */
        $return_array = [];
        foreach ($files as $file) {
            if (!file_exists($file) or !$xml = simplexml_load_string(file_get_contents($file))) {
                error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": $file could not be loaded!");
                return false;
            }

            $return_array[] = [
                "name" => $xml['name'],
                "time" => intval($xml->record[0]['time']),
                "memory" => isset($xml->record[0]->memory) ? self::formatFloat((float) $xml->record[0]->memory, 1) : null,
                "cpu" => isset($xml->record[0]->cpu) ? self::formatFloat((float) $xml->record[0]->cpu, 1) : null,
                "swap" => isset($xml->record[0]->swap) ? self::formatFloat((float) $xml->record[0]->swap, 1) : null,
                "status" => isset($xml->record[0]->status) ? self::formatFloat((float) $xml->record[0]->status, 1) : null
            ];
        }

        return $return_array;
    }

    /**
     * Function to return XML of the server id
     */
    public static function getInformationServerID(int $server_id): \SimpleXMLElement|false
    {
        /* First retrieve the server configuration */
        $server_file = self::dataDir() . $server_id . "-server.xml";

        if (!file_exists($server_file) or !$server_xml = simplexml_load_string(file_get_contents($server_file))) {
            error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": $server_file could not be loaded!");
            return false;
        }

        return $server_xml;
    }

    /**
     * Function to return list of log files for the server id and optional for a specific service
     */
    public static function getLogFilesForServerID(int $server_id, string $specific_services = ""): array|false
    {
        /* Check the directory for the Monit instance ID */
        $files = [];

        $use_sqlite = self::config()['use_sqlite'] ?? false;

        if ($use_sqlite) {
            try {
                $pdo = self::openDatabase($server_id, true);

                $stmt = $pdo->prepare("SELECT name, type FROM service_type");
                $stmt->execute();
                $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                usort($services, fn($a, $b) => strnatcmp($a['name'], $b['name']));

                foreach ($services as $service) {
                    if ($specific_services === "" || $service['name'] === $specific_services) {
                        $files[] = self::dataDir() . $server_id . "/" . $service['name'] . ".xml";
                    }
                }
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        } else {
            foreach (glob(self::dataDir() . $server_id . "/" . $specific_services . "*.xml") as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Function to delete all datafiles bound to a server id and optionally to a filename
     */
    public static function deleteDataFiles(int $server_id, string|false $xml_file_name = false): bool
    {
        $allow_delete = self::config()['allow_delete'] ?? false;

        if ($allow_delete !== true) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Deleting data files is not allowed by configuration.");
            return false;
        }

        $use_sqlite = self::config()['use_sqlite'] ?? false;

        if ($use_sqlite) {
            try {
                $pdo = self::openDatabase($server_id, false);

                if (strlen($xml_file_name) < 0) { // We are deleting everything to this server id
                    $pdo->exec("DELETE FROM service_type");

                    for ($i = 0; $i <= 8; $i++) {
                        $pdo->exec("DELETE FROM service_$i");
                    }
                } else { // Only delete specific data file
                    $stmt = $pdo->prepare("SELECT type FROM service_type WHERE name = ?");
                    $stmt->execute([$xml_file_name]);
                    $type = (int) $stmt->fetchColumn() ?? null;

                    if ($type !== null) {
                        $pdo->prepare("DELETE FROM service_type WHERE name = ?")->execute([$xml_file_name]);
                        $pdo->prepare("DELETE FROM service_$type WHERE name = ?")->execute([$xml_file_name]);
                    }
                }

                return true;
            } catch (\PDOException $e) {
                error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
                return false;
            }
        }

        if (strlen($xml_file_name) < 0) { // We are deleting everything to this server id
            $dirname = self::dataDir() . $server_id . "/";

            // First everything in the data directory
            foreach (glob($dirname."*") as $file) {
                if (!unlink($file)) {
                    error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": Could not delete $file");
                    return false;
                }
            }

            // Now the data directory itself
            if (!unlink($dirname)) {
                error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": Could not delete $dirname");
                return false;
            }

            // Now the server file
            $server_file = self::dataDir() . $server_id . "-server.xml";
            if (!unlink($server_file)) {
                error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": Could not delete $server_file");
                return false;
            }
        } else { // Only delete specific data file
            foreach (glob(self::dataDir() . $server_id . "/" . $xml_file_name . "*") as $file) {
                if (!unlink($file)) {
                    error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Could not delete $file");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A file rotator function, will rotate specific filename depending on size and limitation
     */
    public static function rotateFiles(string $filename, int $chunk_size, int $limit_of_chunks): bool
    {
        /* Chunk rotating */
        if (intval($chunk_size) > 0) {
            if (file_exists($filename) && filesize($filename) > $chunk_size) { // If file size are larger than allowed chunk size, rotate it
                $files = glob($filename.".*");

                usort($files, ["MonitGraph\Base","sortRotatedFilesLastFirst"]);

                for ($i = 0; $i < count($files); $i++) {
                    $number = str_replace($filename.".", "", $files[$i]); // Get the actual number of the file
                    $number = intval($number)+1; // The new number to be used

                    if ($limit_of_chunks == 0 || $number < $limit_of_chunks) { // Rotate chunk
                        if (!rename($files[$i], $filename.".".$number)) {
                            error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": ".$files[$i]." could not be rename to ".$filename.".".$number."");
                            return false;
                        }
                    } else { // If this chunk will be too many for defined, delete it
                        if (!unlink($files[$i])) {
                            error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": could not unlink ".$files[$i]."");
                            return false;
                        }
                    }
                }

                // Finally rename the current head
                if (!rename($filename, $filename.".0")) {
                    error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": ".$filename." could not be rename to ".$filename.".0");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Cleanup old database records based on the maximum record age
     */
    public static function deleteOldDatabaseRecords(\PDO $pdo, int $time, int $max_record_age): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT value FROM properties WHERE variable = 'cleanup'");
            $stmt->execute();
            $last = (int) ($stmt->fetchColumn() ?: 0);

            if (($time - $last) > 23 * 3600) { // Only execute cleanup once every 23 hours
                $pdo->prepare("INSERT INTO properties (variable, value) VALUES (?, ?) ON CONFLICT(variable) DO UPDATE SET value = excluded.value")->execute(['cleanup', $time]);

                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name GLOB 'service_[0-9]*'")->fetchAll(\PDO::FETCH_COLUMN);

                $parts = array_map(function ($table) {
                    return "SELECT time FROM $table";
                }, $tables);

                $stmt = $pdo->query("SELECT MAX(time) AS max_time FROM (" . implode(" UNION ALL ", $parts) . ") t");
                $max = $stmt->fetchColumn();

                if ($max > 0) {
                    $threshold = $max - $max_record_age;

                    foreach ($tables as $table) {
                        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE time < ?");
                        $stmt->execute([$threshold]);
                    }
                }
            }
        } catch (\PDOException $e) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
            return false;
        }

        /*try {
            $stmt = $pdo->prepare("SELECT value FROM properties WHERE variable = 'vacuum'");
            $stmt->execute();
            $last = (int) ($stmt->fetchColumn() ?: 0);

            if (($time - $last) > 167 * 3600) { // Only execute vacuum once every ~7 days
                $pdo->prepare("INSERT INTO properties (variable, value) VALUES (?, ?) ON CONFLICT(variable) DO UPDATE SET value = excluded.value")->execute(['vacuum', $time]);

                $count = (int) ($pdo->query("PRAGMA page_count")->fetchColumn() ?: 0);
                $free = (int) ($pdo->query("PRAGMA freelist_count")->fetchColumn() ?: 0);
                $ratio = $free / max($count, 1);

                // Vacuum when >25% of pages are unused
                if ($ratio > 0.25) {
                    $pdo->exec("VACUUM");
                }
            }
        } catch (\PDOException $e) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Database error: {$e->getMessage()}");
            return false;
        }*/

        return true;
    }

    /**
     * Sorting option for files, used for rotating files
     */
    public static function sortRotatedFilesLastFirst(string $file1, string $file2): int
    {
        $number1 = intval(substr(strrchr($file1, "."), 1));
        $number2 = intval(substr(strrchr($file2, "."), 1));

        if ($number1 == $number2) {
            return 0;
        }

        return ($number1 < $number2) ? 1 : -1;
    }

    /**
     * A function to convert php.ini notation for numbers to integer (e.g. '25M')
     */
    public static function letToNum(string $v): int
    {
        $l = substr($v, -1);
        $ret = substr($v, 0, -1);

        switch (strtoupper($l)) {
            case 'P':
                $ret *= 1024;
                # We'll continue to multiply
            case 'T':
                $ret *= 1024;
                # We'll continue to multiply
            case 'G':
                $ret *= 1024;
                # We'll continue to multiply
            case 'M':
                $ret *= 1024;
                # We'll continue to multiply
            case 'K':
                $ret *= 1024;
                break;
        }

        return $ret;
    }

    /**
     * Return true/false if server id is valid
     */
    public static function isServerIDValid(mixed $server_id): int|false
    {
        if (strlen($server_id) > 0 && intval($server_id)) {
            return intval($server_id);
        }

        error_log("[".self::IDENTIFIER."] ".__FILE__." line ".__LINE__.": Server ID is not valid $server_id!");
        return false;
    }

    /**
     * Return the real percentage usage
     */
    public static function getMonitPercentage(\SimpleXMLElement $xml): float
    {
        if (isset($xml->percenttotal)) {
            return (float) $xml->percenttotal;
        } else {
            return (float) $xml->percent;
        }
    }

    /**
     * Combine all values recursively from a field into a single float value
     */
    public static function combineChildrenValues(\SimpleXMLElement $xml): float
    {
        $total = 0.0;

        foreach ($xml->children() as $child) {
            if ($child->count() > 0) {
                $total += self::combineChildrenValues($child);
            } else {
                $total += (float) $child;
            }
        }

        return $total;
    }

    /**
     * Format to float number
     */
    public static function formatFloat(mixed $number, int $decimals = 1): float
    {
        if (!is_numeric($number)) {
            error_log("[" . self::IDENTIFIER . "] " . __FILE__ . " line " . __LINE__ . ": Value is not numeric: " . var_export($number, true));
            return 0.0;
        }

        if ($number == 0) {
            return 0.0;
        }

        return (float) round($number, $decimals);
    }
}
