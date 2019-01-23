<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * displays the server status > processes list
 *
 * @package PhpMyAdmin
 */
declare(strict_types=1);

use PhpMyAdmin\Response;
use PhpMyAdmin\Server\Status\Data;
use PhpMyAdmin\Server\Status\Processes;

if (! defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
}

require_once ROOT_PATH . 'libraries/common.inc.php';
require_once ROOT_PATH . 'libraries/server_common.inc.php';

/**
 * Replication library
 */
require_once ROOT_PATH . 'libraries/replication.inc.php';

$serverStatusData = new Data();
$response = Response::getInstance();

/**
 * Kills a selected process
 * on ajax request
 */
if ($response->isAjax() && ! empty($_POST['kill'])) {
    $kill = intval($_POST['kill']);
    $query = $GLOBALS['dbi']->getKillQuery($kill);
    if ($GLOBALS['dbi']->tryQuery($query)) {
        $message = PhpMyAdmin\Message::success(
            __('Thread %s was successfully killed.')
        );
        $response->setRequestStatus(true);
    } else {
        $message = PhpMyAdmin\Message::error(
            __(
                'phpMyAdmin was unable to kill thread %s.'
                . ' It probably has already been closed.'
            )
        );
        $response->setRequestStatus(false);
    }
    $message->addParam($kill);
    $response->addJSON('message', $message);
} elseif ($response->isAjax() && ! empty($_POST['refresh'])) {
    // Only sends the process list table
    $response->addHTML(Processes::getHtmlForServerProcesslist());
} else {
    // Load the full page
    $header   = $response->getHeader();
    $scripts  = $header->getScripts();
    $scripts->addFile('server_status_processes.js');
    $response->addHTML('<div>');
    $response->addHTML($serverStatusData->getMenuHtml());
    $response->addHTML(Processes::getHtmlForProcessListFilter());
    $response->addHTML(Processes::getHtmlForServerProcesslist());
    $response->addHTML(Processes::getHtmlForProcessListAutoRefresh());
    $response->addHTML('</div>');
}
exit;
