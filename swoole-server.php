<?php

use Swoole\Http\Request;
use Swoole\Http\Response;

$server = new swoole_http_server('0.0.0.0', 8080, SWOOLE_BASE);
$server->set([
    'worker_num' => NUMCORES
]);

$pool = new DatabasePool();

/**
 *
 * @param string $database_type
 * @param int $queries
 *
 * @return string
 */
$home = function (string $database_type, int $queries = 10) use ($pool): string {
    $db = $pool->get($database_type);

    // Create an array with the response string.
    $arr = [];

    // Define query
    $db->db_test = $db->db_test ?? $db->prepare('SELECT id, firstName, lastName FROM users LIMIT ?');

    $arr = $db->db_test->execute([$queries]);

    $pool->put($db);

    return json_encode($arr);
};

/**
 * Returns users
 *
 * @param string $database_type
 *
 * @return string
 */
$users = function (string $database_type) use ($pool): string {
    $db = $pool->get($database_type);

    $user = [];
    $db->users = $db->user_test ?? $db->prepare('SELECT id, firstName, lastName FROM users');
    $arr = $db->user_test->execute([]);
    foreach ($arr as $row) {
        $user[$row['id']] = $row['firstName'];
    }

    $html = '<!DOCTYPE html><html><head><title>Users</title></head><body><table><tr><th>id</th><th>message</th></tr>';
    foreach ($user as $id => $firstName) {
        $firstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $html .= "<tr><td>{$id}</td><td>{$firstName}</td></tr>";
    }

    $html .= '</table></body></html>';

    $pool->put($db);

    return $html;
};

/**
 * webhook
 *
 * @param string $database_type
 * @param int $queries
 *
 * @return string
 */
$ewbhook = function (string $database_type, string $firstName, string $lastName) use ($pool): string {
    $db = $pool->get($database_type);

    $arr = [];
    $db->updates_test_update = $db->updates_test_update ?? $db->prepare('UPDATE users SET firstName = ?, lastName =? WHERE id = ?');

    $ret = $db->updates_test_update->execute([$firstName, $lastName, $id]);

    $pool->put($db);

    return json_encode($ret);
};

/**
 * On start of the PHP worker. One worker per server process is started.
 */
$server->on('workerStart', function () use ($pool) {
    $pool->set_host_ip();
});

/**
 * On every request to the (web)server, execute the following code
 */
$server->on('request', function (Request $req, Response $res) use ($db, $users, $updates) {

    switch ($req->server['request_uri']) {

        case '/':
            $res->header('Content-Type', 'application/json');

            if (isset($req->get['queries'])) {
                $res->end($home('postgres', (int)$req->get['queries']));
            } else {
                $res->end($home('postgres', 10));
            }
            break;

        case '/users':
            $res->header('Content-Type', 'text/html; charset=utf-8');
            $res->end($users('postgres'));
            break;

        case '/webhook':
            $res->header('Content-Type', 'application/json');

            if (isset($req->get['firstName'])) {
                $res->end($updates('postgres', $req->get['firstName'], $req->get['lastName']));
            } else {
                $res->end($updates('postgres', -1));
            }
            break;
    }

});

$server->start();

/**
 * Class DatabasePool
 *
 * Deal with the fact that Swoole 2.1.3 has no build in database pooling
 */
class DatabasePool
{
    private $server = [
        'host' => '',
        'user' => 'user',
        'password' => 'pass',
        'database' => 'users'
    ];

    private $pool;
    private $pool_count = 0;

    function __construct()
    {
        $this->pool = new \SplQueue;
    }

    function set_host_ip()
    {
        if (empty($this->server['host'])) {
            $tfb_database_ip = Swoole\Coroutine::gethostbyname('localhost');
            $this->server['host'] = $tfb_database_ip;
        }
    }

    function put($db)
    {
        $this->pool->enqueue($db);
        $this->pool_count++;
    }

    function get(string $server_type)
    {
        if ($this->pool_count > 0) {
            $this->pool_count--;
            return $this->pool->dequeue();
        }
        
        $db = new Swoole\Coroutine\PostgreSql;

        $db->connect($this->server);

        if ($db == false) {
            return false;
        }

        return $db;
    }
}
