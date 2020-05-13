<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Database;

use PhpMyAdmin\CentralColumns;
use PhpMyAdmin\Charsets;
use PhpMyAdmin\Common;
use PhpMyAdmin\Config\PageSettings;
use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Display\CreateTable;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Message;
use PhpMyAdmin\Operations;
use PhpMyAdmin\RecentFavoriteTable;
use PhpMyAdmin\Relation;
use PhpMyAdmin\RelationCleanup;
use PhpMyAdmin\Replication;
use PhpMyAdmin\ReplicationInfo;
use PhpMyAdmin\Response;
use PhpMyAdmin\Sanitize;
use PhpMyAdmin\Sql;
use PhpMyAdmin\Table;
use PhpMyAdmin\Template;
use PhpMyAdmin\Tracker;
use PhpMyAdmin\Transformations;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;
use function array_search;
use function ceil;
use function count;
use function define;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function md5;
use function preg_match;
use function preg_quote;
use function sha1;
use function sprintf;
use function str_replace;
use function strlen;
use function strtotime;
use function urlencode;

/**
 * Handles database structure logic
 */
class StructureController extends AbstractController
{
    /** @var int Number of tables */
    protected $numTables;

    /** @var int Current position in the list */
    protected $position;

    /** @var bool DB is information_schema */
    protected $dbIsSystemSchema;

    /** @var int Number of tables */
    protected $totalNumTables;

    /** @var array Tables in the database */
    protected $tables;

    /** @var bool whether stats show or not */
    protected $isShowStats;

    /** @var Relation */
    private $relation;

    /** @var Replication */
    private $replication;

    /** @var Transformations */
    private $transformations;

    /** @var RelationCleanup */
    private $relationCleanup;

    /** @var Operations */
    private $operations;

    /**
     * @param Response          $response        Response instance
     * @param DatabaseInterface $dbi             DatabaseInterface instance
     * @param Template          $template        Template object
     * @param string            $db              Database name
     * @param Relation          $relation        Relation instance
     * @param Replication       $replication     Replication instance
     * @param Transformations   $transformations Transformations instance.
     * @param RelationCleanup   $relationCleanup RelationCleanup instance.
     * @param Operations        $operations      Operations instance.
     */
    public function __construct(
        $response,
        $dbi,
        Template $template,
        $db,
        $relation,
        $replication,
        Transformations $transformations,
        RelationCleanup $relationCleanup,
        Operations $operations
    ) {
        parent::__construct($response, $dbi, $template, $db);
        $this->relation = $relation;
        $this->replication = $replication;
        $this->transformations = $transformations;
        $this->relationCleanup = $relationCleanup;
        $this->operations = $operations;
    }

    /**
     * Retrieves database information for further use
     *
     * @param string $subPart Page part name
     */
    private function getDatabaseInfo(string $subPart): void
    {
        [$tables, $numTables, $totalNumTables,, $isShowStats, $dbIsSystemSchema,,, $position]
            = Util::getDbInfo($this->db, $subPart);

        $this->tables = $tables;
        $this->numTables = $numTables;
        $this->position = $position;
        $this->dbIsSystemSchema = $dbIsSystemSchema;
        $this->totalNumTables = $totalNumTables;
        $this->isShowStats = $isShowStats;
    }

    public function index(): void
    {
        global $cfg;

        $parameters = [
            'sort' => $_REQUEST['sort'] ?? null,
            'sort_order' => $_REQUEST['sort_order'] ?? null,
        ];

        Common::database();

        $this->response->getHeader()->getScripts()->addFiles([
            'database/structure.js',
            'table/change.js',
        ]);

        // Drops/deletes/etc. multiple tables if required
        if ((! empty($_POST['submit_mult']) && isset($_POST['selected_tbl']))
            || isset($_POST['mult_btn'])
        ) {
            $this->multiSubmitAction();
        }

        // Gets the database structure
        $this->getDatabaseInfo('_structure');

        // Checks if there are any tables to be shown on current page.
        // If there are no tables, the user is redirected to the last page
        // having any.
        if ($this->totalNumTables > 0 && $this->position > $this->totalNumTables) {
            $uri = './index.php?route=/database/structure' . Url::getCommonRaw([
                'db' => $this->db,
                'pos' => max(0, $this->totalNumTables - $cfg['MaxTableList']),
                'reload' => 1,
            ], '&');
            Core::sendHeaderLocation($uri);
        }

        ReplicationInfo::load();

        PageSettings::showGroup('DbStructure');

        if ($this->numTables > 0) {
            $urlParams = [
                'pos' => $this->position,
                'db' => $this->db,
            ];
            if (isset($parameters['sort'])) {
                $urlParams['sort'] = $parameters['sort'];
            }
            if (isset($parameters['sort_order'])) {
                $urlParams['sort_order'] = $parameters['sort_order'];
            }
            $listNavigator = Generator::getListNavigator(
                $this->totalNumTables,
                $this->position,
                $urlParams,
                Url::getFromRoute('/database/structure'),
                'frame_content',
                $cfg['MaxTableList']
            );

            $tableList = $this->displayTableList();
        }

        $createTable = '';
        if (empty($this->dbIsSystemSchema)) {
            $createTable = CreateTable::getHtml($this->db);
        }

        $this->render('database/structure/index', [
            'database' => $this->db,
            'has_tables' => $this->numTables > 0,
            'list_navigator_html' => $listNavigator ?? '',
            'table_list_html' => $tableList ?? '',
            'is_system_schema' => ! empty($this->dbIsSystemSchema),
            'create_table_html' => $createTable,
        ]);
    }

    public function addRemoveFavoriteTablesAction(): void
    {
        global $cfg;

        $parameters = [
            'favorite_table' => $_REQUEST['favorite_table'] ?? null,
            'favoriteTables' => $_REQUEST['favoriteTables'] ?? null,
            'sync_favorite_tables' => $_REQUEST['sync_favorite_tables'] ?? null,
        ];

        Common::database();

        if (! $this->response->isAjax()) {
            return;
        }

        $favoriteInstance = RecentFavoriteTable::getInstance('favorite');
        if (isset($parameters['favoriteTables'])) {
            $favoriteTables = json_decode($parameters['favoriteTables'], true);
        } else {
            $favoriteTables = [];
        }
        // Required to keep each user's preferences separate.
        $user = sha1($cfg['Server']['user']);

        // Request for Synchronization of favorite tables.
        if (isset($parameters['sync_favorite_tables'])) {
            $cfgRelation = $this->relation->getRelationsParam();
            if ($cfgRelation['favoritework']) {
                $this->response->addJSON($this->synchronizeFavoriteTables(
                    $favoriteInstance,
                    $user,
                    $favoriteTables
                ));
            }

            return;
        }
        $changes = true;
        $titles = Util::buildActionTitles();
        $favoriteTable = $parameters['favorite_table'] ?? '';
        $alreadyFavorite = $this->checkFavoriteTable($favoriteTable);

        if (isset($_REQUEST['remove_favorite'])) {
            if ($alreadyFavorite) {
                // If already in favorite list, remove it.
                $favoriteInstance->remove($this->db, $favoriteTable);
                $alreadyFavorite = false; // for favorite_anchor template
            }
        } elseif (isset($_REQUEST['add_favorite'])) {
            if (! $alreadyFavorite) {
                $numTables = count($favoriteInstance->getTables());
                if ($numTables == $cfg['NumFavoriteTables']) {
                    $changes = false;
                } else {
                    // Otherwise add to favorite list.
                    $favoriteInstance->add($this->db, $favoriteTable);
                    $alreadyFavorite = true;  // for favorite_anchor template
                }
            }
        }

        $favoriteTables[$user] = $favoriteInstance->getTables();

        $json = [];
        $json['changes'] = $changes;
        if (! $changes) {
            $json['message'] = $this->template->render('components/error_message', [
                'msg' => __('Favorite List is full!'),
            ]);
            $this->response->addJSON($json);

            return;
        }
        // Check if current table is already in favorite list.
        $favoriteParams = [
            'db' => $this->db,
            'ajax_request' => true,
            'favorite_table' => $favoriteTable,
            ($alreadyFavorite ? 'remove' : 'add') . '_favorite' => true,
        ];

        $json['user'] = $user;
        $json['favoriteTables'] = json_encode($favoriteTables);
        $json['list'] = $favoriteInstance->getHtmlList();
        $json['anchor'] = $this->template->render('database/structure/favorite_anchor', [
            'table_name_hash' => md5($favoriteTable),
            'db_table_name_hash' => md5($this->db . '.' . $favoriteTable),
            'fav_params' => $favoriteParams,
            'already_favorite' => $alreadyFavorite,
            'titles' => $titles,
        ]);

        $this->response->addJSON($json);
    }

    /**
     * Handles request for real row count on database level view page.
     */
    public function handleRealRowCountRequestAction(): void
    {
        $parameters = [
            'real_row_count_all' => $_REQUEST['real_row_count_all'] ?? null,
            'table' => $_REQUEST['table'] ?? null,
        ];

        Common::database();

        if (! $this->response->isAjax()) {
            return;
        }

        // If there is a request to update all table's row count.
        if (! isset($parameters['real_row_count_all'])) {
            // Get the real row count for the table.
            $realRowCount = $this->dbi
                ->getTable($this->db, (string) $parameters['table'])
                ->getRealRowCountTable();
            // Format the number.
            $realRowCount = Util::formatNumber($realRowCount, 0);

            $this->response->addJSON(['real_row_count' => $realRowCount]);

            return;
        }

        // Array to store the results.
        $realRowCountAll = [];
        // Iterate over each table and fetch real row count.
        foreach ($this->tables as $table) {
            $rowCount = $this->dbi
                ->getTable($this->db, $table['TABLE_NAME'])
                ->getRealRowCountTable();
            $realRowCountAll[] = [
                'table' => $table['TABLE_NAME'],
                'row_count' => $rowCount,
            ];
        }

        $this->response->addJSON(['real_row_count_all' => json_encode($realRowCountAll)]);
    }

    /**
     * Handles actions related to multiple tables
     */
    public function multiSubmitAction(): void
    {
        global $containerBuilder, $db, $table, $from_prefix, $goto, $message, $err_url;
        global $mult_btn, $query_type, $reload, $dblist, $selected, $sql_query;
        global $submit_mult, $table_type, $to_prefix, $url_query, $pmaThemeImage;

        if (isset($_POST['error']) && $_POST['error'] !== false) {
            return;
        }

        $action = Url::getFromRoute('/database/structure');
        $err_url = Url::getFromRoute('/database/structure', ['db' => $this->db]);

        $from_prefix = $_POST['from_prefix'] ?? $from_prefix ?? null;
        $goto = $_POST['goto'] ?? $goto ?? null;
        $mult_btn = $_POST['mult_btn'] ?? $mult_btn ?? null;
        $query_type = $_POST['query_type'] ?? $query_type ?? null;
        $reload = $_POST['reload'] ?? $reload ?? null;
        $selected = $_POST['selected'] ?? $selected ?? null;
        $sql_query = $_POST['sql_query'] ?? $sql_query ?? null;
        $submit_mult = $_POST['submit_mult'] ?? $submit_mult ?? null;
        $table_type = $_POST['table_type'] ?? $table_type ?? null;
        $to_prefix = $_POST['to_prefix'] ?? $to_prefix ?? null;
        $url_query = $_POST['url_query'] ?? $url_query ?? null;

        /**
         * Prepares the work and runs some other scripts if required
         */
        if (! empty($submit_mult)
            && $submit_mult != __('With selected:')
            && ! empty($_POST['selected_tbl'])
        ) {
            // phpcs:disable PSR1.Files.SideEffects
            define('PMA_SUBMIT_MULT', 1);
            // phpcs:enable

            if (! empty($_POST['selected_tbl'])) {
                // coming from database structure view - do something with
                // selected tables
                $selected = $_POST['selected_tbl'];
                $centralColumns = new CentralColumns($this->dbi);
                switch ($submit_mult) {
                    case 'add_prefix_tbl':
                    case 'replace_prefix_tbl':
                    case 'copy_tbl_change_prefix':
                    case 'drop_tbl':
                    case 'empty_tbl':
                        $what = $submit_mult;
                        break;
                    case 'check_tbl':
                    case 'optimize_tbl':
                    case 'repair_tbl':
                    case 'analyze_tbl':
                    case 'checksum_tbl':
                        $query_type = $submit_mult;
                        unset($submit_mult);
                        $mult_btn   = __('Yes');
                        break;
                    case 'export':
                        unset($submit_mult);
                        /** @var ExportController $controller */
                        $controller = $containerBuilder->get(ExportController::class);
                        $controller->index();
                        exit;
                    case 'copy_tbl':
                        $_url_params = [
                            'query_type' => 'copy_tbl',
                            'db' => $db,
                        ];
                        foreach ($selected as $selectedValue) {
                            $_url_params['selected'][] = $selectedValue;
                        }

                        $databasesList = $dblist->databases;
                        foreach ($databasesList as $key => $databaseName) {
                            if ($databaseName == $db) {
                                $databasesList->offsetUnset($key);
                                break;
                            }
                        }

                        $this->response->disable();
                        $this->render('mult_submits/copy_multiple_tables', [
                            'action' => $action,
                            'url_params' => $_url_params,
                            'options' => $databasesList->getList(),
                        ]);
                        exit;
                    case 'show_create':
                        $show_create = $this->template->render('database/structure/show_create', [
                            'db' => $db,
                            'db_objects' => $selected,
                            'dbi' => $this->dbi,
                        ]);
                        // Send response to client.
                        $this->response->addJSON('message', $show_create);
                        exit;
                    case 'sync_unique_columns_central_list':
                        $centralColsError = $centralColumns->syncUniqueColumns(
                            $selected
                        );
                        break;
                    case 'delete_unique_columns_central_list':
                        $centralColsError = $centralColumns->deleteColumnsFromList(
                            $_POST['db'],
                            $selected
                        );
                        break;
                    case 'make_consistent_with_central_list':
                        $centralColsError = $centralColumns->makeConsistentWithList(
                            $db,
                            $selected
                        );
                        break;
                } // end switch
            }
        }

        if (empty($db)) {
            $db = '';
        }
        if (empty($table)) {
            $table = '';
        }
        $views = $this->dbi->getVirtualTables($db);

        /**
         * Displays the confirmation form if required
         */
        if (! empty($submit_mult) && ! empty($what)) {
            unset($message);

            if (strlen($table) > 0) {
                Common::table();
                $url_query .= Url::getCommon([
                    'goto' => Url::getFromRoute('/table/sql'),
                    'back' => Url::getFromRoute('/table/sql'),
                ], '&');
            } elseif (strlen($db) > 0) {
                Common::database();

                list(
                    $tables,
                    $num_tables,
                    $total_num_tables,
                    $sub_part,
                    $is_show_stats,
                    $db_is_system_schema,
                    $tooltip_truename,
                    $tooltip_aliasname,
                    $pos
                ) = Util::getDbInfo($db, $sub_part ?? '');
            } else {
                Common::server();
            }

            $full_query_views = null;
            $full_query = '';

            if ($what == 'drop_tbl') {
                $full_query_views = '';
            }

            foreach ($selected as $selectedValue) {
                switch ($what) {
                    case 'drop_tbl':
                        $current = $selectedValue;
                        if (! empty($views) && in_array($current, $views)) {
                            $full_query_views .= (empty($full_query_views) ? 'DROP VIEW ' : ', ')
                                . Util::backquote(htmlspecialchars($current));
                        } else {
                            $full_query .= (empty($full_query) ? 'DROP TABLE ' : ', ')
                                . Util::backquote(htmlspecialchars($current));
                        }
                        break;

                    case 'empty_tbl':
                        $full_query .= 'TRUNCATE ';
                        $full_query .= Util::backquote(htmlspecialchars($selectedValue))
                            . ';<br>';
                        break;
                }
            }

            if ($what == 'drop_tbl') {
                if (! empty($full_query)) {
                    $full_query .= ';<br>' . "\n";
                }
                if (! empty($full_query_views)) {
                    $full_query .= $full_query_views . ';<br>' . "\n";
                }
                unset($full_query_views);
            }

            $full_query_views = $full_query_views ?? null;

            $_url_params = [
                'query_type' => $what,
                'db' => $db,
            ];
            foreach ($selected as $selectedValue) {
                $_url_params['selected'][] = $selectedValue;
            }
            if ($what == 'drop_tbl' && ! empty($views)) {
                foreach ($views as $current) {
                    $_url_params['views'][] = $current;
                }
            }

            if ($what == 'replace_prefix_tbl' || $what == 'copy_tbl_change_prefix') {
                $this->response->disable();
                $this->render('mult_submits/replace_prefix_table', [
                    'action' => $action,
                    'url_params' => $_url_params,
                ]);
            } elseif ($what == 'add_prefix_tbl') {
                $this->response->disable();
                $this->render('mult_submits/add_prefix_table', [
                    'action' => $action,
                    'url_params' => $_url_params,
                ]);
            } else {
                $this->render('mult_submits/other_actions', [
                    'action' => $action,
                    'url_params' => $_url_params,
                    'what' => $what,
                    'full_query' => $full_query,
                    'is_foreign_key_check' => Util::isForeignKeyCheck(),
                ]);
            }
            exit;
        } elseif (! empty($mult_btn) && $mult_btn == __('Yes')) {
            $default_fk_check_value = false;
            if ($query_type == 'drop_tbl' || $query_type == 'empty_tbl') {
                $default_fk_check_value = Util::handleDisableFKCheckInit();
            }

            $aQuery = '';
            $sql_query = '';
            $sql_query_views = null;
            // whether to run query after each pass
            $run_parts = false;
            // whether to execute the query at the end (to display results)
            $execute_query_later = false;
            $result = null;

            if ($query_type == 'drop_tbl') {
                $sql_query_views = '';
            }

            $selectedCount = count($selected);
            $deletes = false;
            $copyTable = false;

            for ($i = 0; $i < $selectedCount; $i++) {
                switch ($query_type) {
                    case 'drop_tbl':
                        $this->relationCleanup->table($db, $selected[$i]);
                        $current = $selected[$i];
                        if (! empty($views) && in_array($current, $views)) {
                            $sql_query_views .= (empty($sql_query_views) ? 'DROP VIEW ' : ', ')
                                . Util::backquote($current);
                        } else {
                            $sql_query .= (empty($sql_query) ? 'DROP TABLE ' : ', ')
                                . Util::backquote($current);
                        }
                        $reload    = 1;
                        break;

                    case 'check_tbl':
                        $sql_query .= (empty($sql_query) ? 'CHECK TABLE ' : ', ')
                            . Util::backquote($selected[$i]);
                        $execute_query_later = true;
                        break;

                    case 'optimize_tbl':
                        $sql_query .= (empty($sql_query) ? 'OPTIMIZE TABLE ' : ', ')
                            . Util::backquote($selected[$i]);
                        $execute_query_later = true;
                        break;

                    case 'analyze_tbl':
                        $sql_query .= (empty($sql_query) ? 'ANALYZE TABLE ' : ', ')
                            . Util::backquote($selected[$i]);
                        $execute_query_later = true;
                        break;

                    case 'checksum_tbl':
                        $sql_query .= (empty($sql_query) ? 'CHECKSUM TABLE ' : ', ')
                            . Util::backquote($selected[$i]);
                        $execute_query_later = true;
                        break;

                    case 'repair_tbl':
                        $sql_query .= (empty($sql_query) ? 'REPAIR TABLE ' : ', ')
                            . Util::backquote($selected[$i]);
                        $execute_query_later = true;
                        break;

                    case 'empty_tbl':
                        $deletes = true;
                        $aQuery = 'TRUNCATE ';
                        $aQuery .= Util::backquote($selected[$i]);
                        $run_parts = true;
                        break;

                    case 'add_prefix_tbl':
                        $newTableName = $_POST['add_prefix'] . $selected[$i];
                        // ADD PREFIX TO TABLE NAME
                        $aQuery = 'ALTER TABLE '
                            . Util::backquote($selected[$i])
                            . ' RENAME '
                            . Util::backquote($newTableName);
                        $run_parts = true;
                        break;

                    case 'replace_prefix_tbl':
                        $current = $selected[$i];
                        $subFromPrefix = mb_substr(
                            $current,
                            0,
                            mb_strlen((string) $from_prefix)
                        );
                        if ($subFromPrefix == $from_prefix) {
                            $newTableName = $to_prefix
                                . mb_substr(
                                    $current,
                                    mb_strlen((string) $from_prefix)
                                );
                        } else {
                            $newTableName = $current;
                        }
                        // CHANGE PREFIX PATTERN
                        $aQuery = 'ALTER TABLE '
                            . Util::backquote($selected[$i])
                            . ' RENAME '
                            . Util::backquote($newTableName);
                        $run_parts = true;
                        break;

                    case 'copy_tbl_change_prefix':
                        $run_parts = true;
                        $copyTable = true;

                        $current = $selected[$i];
                        $newTableName = $to_prefix .
                            mb_substr($current, mb_strlen((string) $from_prefix));

                        // COPY TABLE AND CHANGE PREFIX PATTERN
                        Table::moveCopy(
                            $db,
                            $current,
                            $db,
                            $newTableName,
                            'data',
                            false,
                            'one_table'
                        );
                        break;

                    case 'copy_tbl':
                        $run_parts = true;
                        $copyTable = true;
                        Table::moveCopy(
                            $db,
                            $selected[$i],
                            $_POST['target_db'],
                            $selected[$i],
                            $_POST['what'],
                            false,
                            'one_table'
                        );
                        if (isset($_POST['adjust_privileges']) && ! empty($_POST['adjust_privileges'])) {
                            $this->operations->adjustPrivilegesCopyTable(
                                $db,
                                $selected[$i],
                                $_POST['target_db'],
                                $selected[$i]
                            );
                        }
                        break;
                }

                // All "DROP TABLE", "DROP FIELD", "OPTIMIZE TABLE" and "REPAIR TABLE"
                // statements will be run at once below
                if ($run_parts && ! $copyTable) {
                    $sql_query .= $aQuery . ';' . "\n";
                    $this->dbi->selectDb($db);
                    $result = $this->dbi->query($aQuery);

                    if ($query_type == 'drop_tbl') {
                        $this->transformations->clear($db, $selected[$i]);
                    }
                }
            }

            if ($deletes && ! empty($_REQUEST['pos'])) {
                $sql = new Sql();
                $_REQUEST['pos'] = $sql->calculatePosForLastPage(
                    $db,
                    $table,
                    $_REQUEST['pos'] ?? null
                );
            }

            if ($query_type == 'drop_tbl') {
                if (! empty($sql_query)) {
                    $sql_query .= ';';
                } elseif (! empty($sql_query_views)) {
                    $sql_query = $sql_query_views . ';';
                    unset($sql_query_views);
                }
            }

            // Unset cache values for tables count, issue #14205
            if ($query_type === 'drop_tbl' && isset($_SESSION['tmpval'])) {
                if (isset($_SESSION['tmpval']['table_limit_offset'])) {
                    unset($_SESSION['tmpval']['table_limit_offset']);
                }

                if (isset($_SESSION['tmpval']['table_limit_offset_db'])) {
                    unset($_SESSION['tmpval']['table_limit_offset_db']);
                }
            }

            if ($execute_query_later) {
                $sql = new Sql();
                $sql->executeQueryAndSendQueryResponse(
                    null, // analyzed_sql_results
                    false, // is_gotofile
                    $db, // db
                    $table, // table
                    null, // find_real_end
                    null, // sql_query_for_bookmark
                    null, // extra_data
                    null, // message_to_show
                    null, // message
                    null, // sql_data
                    $goto, // goto
                    $pmaThemeImage, // pmaThemeImage
                    null, // disp_query
                    null, // disp_message
                    $query_type, // query_type
                    $sql_query, // sql_query
                    $selected, // selectedTables
                    null // complete_query
                );
            } elseif (! $run_parts) {
                $this->dbi->selectDb($db);
                $result = $this->dbi->tryQuery($sql_query);
                if ($result && ! empty($sql_query_views)) {
                    $sql_query .= ' ' . $sql_query_views . ';';
                    $result = $this->dbi->tryQuery($sql_query_views);
                    unset($sql_query_views);
                }

                if (! $result) {
                    $message = Message::error((string) $this->dbi->getError());
                }
            }
            if ($query_type == 'drop_tbl' || $query_type == 'empty_tbl') {
                Util::handleDisableFKCheckCleanup($default_fk_check_value);
            }
        } elseif (isset($submit_mult)
            && ($submit_mult == 'sync_unique_columns_central_list'
                || $submit_mult == 'delete_unique_columns_central_list'
                || $submit_mult == 'add_to_central_columns'
                || $submit_mult == 'remove_from_central_columns'
                || $submit_mult == 'make_consistent_with_central_list')
        ) {
            if (isset($centralColsError) && $centralColsError !== true) {
                $message = $centralColsError;
            } else {
                $message = Message::success(__('Success!'));
            }
        } else {
            $message = Message::success(__('No change'));
        }

        if (empty($_POST['message'])) {
            $_POST['message'] = Message::success();
        }
    }

    /**
     * Displays the list of tables
     *
     * @return string HTML
     */
    protected function displayTableList(): string
    {
        $html = '';

        // filtering
        $html .= $this->template->render('filter', ['filter_value' => '']);

        $i = $sum_entries = 0;
        $overhead_check = false;
        $create_time_all = '';
        $update_time_all = '';
        $check_time_all = '';
        $num_columns = $GLOBALS['cfg']['PropertiesNumColumns'] > 1
            ? ceil($this->numTables / $GLOBALS['cfg']['PropertiesNumColumns']) + 1
            : 0;
        $row_count      = 0;
        $sum_size       = 0;
        $overhead_size  = 0;

        $hidden_fields = [];
        $overall_approx_rows = false;
        $structure_table_rows = [];
        foreach ($this->tables as $keyname => $current_table) {
            // Get valid statistics whatever is the table type

            $drop_query = '';
            $drop_message = '';
            $overhead = '';
            $input_class = ['checkall'];

            // Sets parameters for links
            $tableUrlParams = [
                'db' => $this->db,
                'table' => $current_table['TABLE_NAME'],
            ];
            // do not list the previous table's size info for a view

            [
                $current_table,
                $formatted_size,
                $unit,
                $formatted_overhead,
                $overhead_unit,
                $overhead_size,
                $table_is_view,
                $sum_size,
            ] = $this->getStuffForEngineTypeTable(
                    $current_table,
                    $sum_size,
                    $overhead_size
                );

            $curTable = $this->dbi
                ->getTable($this->db, $current_table['TABLE_NAME']);
            if (! $curTable->isMerge()) {
                $sum_entries += $current_table['TABLE_ROWS'];
            }

            $collationDefinition = '---';
            if (isset($current_table['Collation'])) {
                $tableCollation = Charsets::findCollationByName(
                    $this->dbi,
                    $GLOBALS['cfg']['Server']['DisableIS'],
                    $current_table['Collation']
                );
                if ($tableCollation !== null) {
                    $collationDefinition = $this->template->render('database/structure/collation_definition', [
                        'valueTitle' => $tableCollation->getDescription(),
                        'value' => $tableCollation->getName(),
                    ]);
                }
            }

            if ($this->isShowStats) {
                $overhead = '-';
                if ($formatted_overhead != '') {
                    $overhead = $this->template->render('database/structure/overhead', [
                        'table_url_params' => $tableUrlParams,
                        'formatted_overhead' => $formatted_overhead,
                        'overhead_unit' => $overhead_unit,
                    ]);
                    $overhead_check = true;
                    $input_class[] = 'tbl-overhead';
                }
            }

            if ($GLOBALS['cfg']['ShowDbStructureCharset']) {
                $charset = '';
                if (isset($tableCollation)) {
                    $charset = $tableCollation->getCharset();
                }
            }

            if ($GLOBALS['cfg']['ShowDbStructureCreation']) {
                $create_time = $current_table['Create_time'] ?? '';
                if ($create_time
                    && (! $create_time_all
                    || $create_time < $create_time_all)
                ) {
                    $create_time_all = $create_time;
                }
            }

            if ($GLOBALS['cfg']['ShowDbStructureLastUpdate']) {
                $update_time = $current_table['Update_time'] ?? '';
                if ($update_time
                    && (! $update_time_all
                    || $update_time < $update_time_all)
                ) {
                    $update_time_all = $update_time;
                }
            }

            if ($GLOBALS['cfg']['ShowDbStructureLastCheck']) {
                $check_time = $current_table['Check_time'] ?? '';
                if ($check_time
                    && (! $check_time_all
                    || $check_time < $check_time_all)
                ) {
                    $check_time_all = $check_time;
                }
            }

            $truename = $current_table['TABLE_NAME'];

            $i++;

            $row_count++;
            if ($table_is_view) {
                $hidden_fields[] = '<input type="hidden" name="views[]" value="'
                    . htmlspecialchars($current_table['TABLE_NAME']) . '">';
            }

            /*
             * Always activate links for Browse, Search and Empty, even if
             * the icons are greyed, because
             * 1. for views, we don't know the number of rows at this point
             * 2. for tables, another source could have populated them since the
             *    page was generated
             *
             * I could have used the PHP ternary conditional operator but I find
             * the code easier to read without this operator.
             */
            $may_have_rows = $current_table['TABLE_ROWS'] > 0 || $table_is_view;
            $titles = Util::buildActionTitles();

            if (! $this->dbIsSystemSchema) {
                $drop_query = sprintf(
                    'DROP %s %s',
                    $table_is_view || $current_table['ENGINE'] == null ? 'VIEW'
                    : 'TABLE',
                    Util::backquote(
                        $current_table['TABLE_NAME']
                    )
                );
                $drop_message = sprintf(
                    ($table_is_view || $current_table['ENGINE'] == null
                        ? __('View %s has been dropped.')
                        : __('Table %s has been dropped.')),
                    str_replace(
                        ' ',
                        '&nbsp;',
                        htmlspecialchars($current_table['TABLE_NAME'])
                    )
                );
            }

            if ($num_columns > 0
                && $this->numTables > $num_columns
                && ($row_count % $num_columns) == 0
            ) {
                $row_count = 1;

                $html .= $this->template->render('database/structure/table_header', [
                    'db' => $this->db,
                    'db_is_system_schema' => $this->dbIsSystemSchema,
                    'replication' => $GLOBALS['replication_info']['slave']['status'],
                    'properties_num_columns' => $GLOBALS['cfg']['PropertiesNumColumns'],
                    'is_show_stats' => $GLOBALS['is_show_stats'],
                    'show_charset' => $GLOBALS['cfg']['ShowDbStructureCharset'],
                    'show_comment' => $GLOBALS['cfg']['ShowDbStructureComment'],
                    'show_creation' => $GLOBALS['cfg']['ShowDbStructureCreation'],
                    'show_last_update' => $GLOBALS['cfg']['ShowDbStructureLastUpdate'],
                    'show_last_check' => $GLOBALS['cfg']['ShowDbStructureLastCheck'],
                    'num_favorite_tables' => $GLOBALS['cfg']['NumFavoriteTables'],
                    'structure_table_rows' => $structure_table_rows,
                ]);
                $structure_table_rows = [];
            }

            [$approx_rows, $show_superscript] = $this->isRowCountApproximated(
                $current_table,
                $table_is_view
            );

            [$do, $ignored] = $this->getReplicationStatus($truename);

            $structure_table_rows[] = [
                'table_name_hash' => md5($current_table['TABLE_NAME']),
                'db_table_name_hash' => md5($this->db . '.' . $current_table['TABLE_NAME']),
                'db' => $this->db,
                'curr' => $i,
                'input_class' => implode(' ', $input_class),
                'table_is_view' => $table_is_view,
                'current_table' => $current_table,
                'browse_table_title' => $may_have_rows ? $titles['Browse'] : $titles['NoBrowse'],
                'search_table_title' => $may_have_rows ? $titles['Search'] : $titles['NoSearch'],
                'browse_table_label_title' => htmlspecialchars($current_table['TABLE_COMMENT']),
                'browse_table_label_truename' => $truename,
                'empty_table_sql_query' => 'TRUNCATE ' . Util::backquote(
                    $current_table['TABLE_NAME']
                ),
                'empty_table_message_to_show' => urlencode(
                    sprintf(
                        __('Table %s has been emptied.'),
                        htmlspecialchars(
                            $current_table['TABLE_NAME']
                        )
                    )
                ),
                'empty_table_title' => $may_have_rows ? $titles['Empty'] : $titles['NoEmpty'],
                'tracking_icon' => $this->getTrackingIcon($truename),
                'server_slave_status' => $GLOBALS['replication_info']['slave']['status'],
                'table_url_params' => $tableUrlParams,
                'db_is_system_schema' => $this->dbIsSystemSchema,
                'titles' => $titles,
                'drop_query' => $drop_query,
                'drop_message' => $drop_message,
                'collation' => $collationDefinition,
                'formatted_size' => $formatted_size,
                'unit' => $unit,
                'overhead' => $overhead,
                'create_time' => isset($create_time) && $create_time
                        ? Util::localisedDate(strtotime($create_time)) : '-',
                'update_time' => isset($update_time) && $update_time
                        ? Util::localisedDate(strtotime($update_time)) : '-',
                'check_time' => isset($check_time) && $check_time
                        ? Util::localisedDate(strtotime($check_time)) : '-',
                'charset' => $charset ?? '',
                'is_show_stats' => $this->isShowStats,
                'ignored' => $ignored,
                'do' => $do,
                'approx_rows' => $approx_rows,
                'show_superscript' => $show_superscript,
                'already_favorite' => $this->checkFavoriteTable(
                    $current_table['TABLE_NAME']
                ),
                'num_favorite_tables' => $GLOBALS['cfg']['NumFavoriteTables'],
                'properties_num_columns' => $GLOBALS['cfg']['PropertiesNumColumns'],
                'limit_chars' => $GLOBALS['cfg']['LimitChars'],
                'show_charset' => $GLOBALS['cfg']['ShowDbStructureCharset'],
                'show_comment' => $GLOBALS['cfg']['ShowDbStructureComment'],
                'show_creation' => $GLOBALS['cfg']['ShowDbStructureCreation'],
                'show_last_update' => $GLOBALS['cfg']['ShowDbStructureLastUpdate'],
                'show_last_check' => $GLOBALS['cfg']['ShowDbStructureLastCheck'],
            ];

            $overall_approx_rows = $overall_approx_rows || $approx_rows;
        }

        $databaseCollation = [];
        $databaseCharset = '';
        $collation = Charsets::findCollationByName(
            $this->dbi,
            $GLOBALS['cfg']['Server']['DisableIS'],
            $this->dbi->getDbCollation($this->db)
        );
        if ($collation !== null) {
            $databaseCollation = [
                'name' => $collation->getName(),
                'description' => $collation->getDescription(),
            ];
            $databaseCharset = $collation->getCharset();
        }

        // table form
        $html .= $this->template->render('database/structure/table_header', [
            'db' => $this->db,
            'db_is_system_schema' => $this->dbIsSystemSchema,
            'replication' => $GLOBALS['replication_info']['slave']['status'],
            'properties_num_columns' => $GLOBALS['cfg']['PropertiesNumColumns'],
            'is_show_stats' => $this->isShowStats,
            'show_charset' => $GLOBALS['cfg']['ShowDbStructureCharset'],
            'show_comment' => $GLOBALS['cfg']['ShowDbStructureComment'],
            'show_creation' => $GLOBALS['cfg']['ShowDbStructureCreation'],
            'show_last_update' => $GLOBALS['cfg']['ShowDbStructureLastUpdate'],
            'show_last_check' => $GLOBALS['cfg']['ShowDbStructureLastCheck'],
            'num_favorite_tables' => $GLOBALS['cfg']['NumFavoriteTables'],
            'structure_table_rows' => $structure_table_rows,
            'body_for_table_summary' => [
                'num_tables' => $this->numTables,
                'server_slave_status' => $GLOBALS['replication_info']['slave']['status'],
                'db_is_system_schema' => $this->dbIsSystemSchema,
                'sum_entries' => $sum_entries,
                'database_collation' => $databaseCollation,
                'is_show_stats' => $this->isShowStats,
                'database_charset' => $databaseCharset,
                'sum_size' => $sum_size,
                'overhead_size' => $overhead_size,
                'create_time_all' => $create_time_all ? Util::localisedDate(strtotime($create_time_all)) : '-',
                'update_time_all' => $update_time_all ? Util::localisedDate(strtotime($update_time_all)) : '-',
                'check_time_all' => $check_time_all ? Util::localisedDate(strtotime($check_time_all)) : '-',
                'approx_rows' => $overall_approx_rows,
                'num_favorite_tables' => $GLOBALS['cfg']['NumFavoriteTables'],
                'db' => $GLOBALS['db'],
                'properties_num_columns' => $GLOBALS['cfg']['PropertiesNumColumns'],
                'dbi' => $this->dbi,
                'show_charset' => $GLOBALS['cfg']['ShowDbStructureCharset'],
                'show_comment' => $GLOBALS['cfg']['ShowDbStructureComment'],
                'show_creation' => $GLOBALS['cfg']['ShowDbStructureCreation'],
                'show_last_update' => $GLOBALS['cfg']['ShowDbStructureLastUpdate'],
                'show_last_check' => $GLOBALS['cfg']['ShowDbStructureLastCheck'],
            ],
            'check_all_tables' => [
                'pma_theme_image' => $GLOBALS['pmaThemeImage'] ?? null,
                'text_dir' => $GLOBALS['text_dir'],
                'overhead_check' => $overhead_check,
                'db_is_system_schema' => $this->dbIsSystemSchema,
                'hidden_fields' => $hidden_fields,
                'disable_multi_table' => $GLOBALS['cfg']['DisableMultiTableMaintenance'],
                'central_columns_work' => $GLOBALS['cfgRelation']['centralcolumnswork'] ?? null,
            ],
        ]);

        return $html;
    }

    /**
     * Returns the tracking icon if the table is tracked
     *
     * @param string $table table name
     *
     * @return string HTML for tracking icon
     */
    protected function getTrackingIcon(string $table): string
    {
        $tracking_icon = '';
        if (Tracker::isActive()) {
            $is_tracked = Tracker::isTracked($this->db, $table);
            if ($is_tracked
                || Tracker::getVersion($this->db, $table) > 0
            ) {
                $tracking_icon = $this->template->render('database/structure/tracking_icon', [
                    'db' => $this->db,
                    'table' => $table,
                    'is_tracked' => $is_tracked,
                ]);
            }
        }

        return $tracking_icon;
    }

    /**
     * Returns whether the row count is approximated
     *
     * @param array $current_table array containing details about the table
     * @param bool  $table_is_view whether the table is a view
     *
     * @return array
     */
    protected function isRowCountApproximated(
        array $current_table,
        bool $table_is_view
    ): array {
        $approx_rows = false;
        $show_superscript = '';

        // there is a null value in the ENGINE
        // - when the table needs to be repaired, or
        // - when it's a view
        //  so ensure that we'll display "in use" below for a table
        //  that needs to be repaired
        if (isset($current_table['TABLE_ROWS'])
            && ($current_table['ENGINE'] != null || $table_is_view)
        ) {
            // InnoDB/TokuDB table: we did not get an accurate row count
            $approx_rows = ! $table_is_view
                && in_array($current_table['ENGINE'], ['InnoDB', 'TokuDB'])
                && ! $current_table['COUNTED'];

            if ($table_is_view
                && $current_table['TABLE_ROWS'] >= $GLOBALS['cfg']['MaxExactCountViews']
            ) {
                $approx_rows = true;
                $show_superscript = Generator::showHint(
                    Sanitize::sanitizeMessage(
                        sprintf(
                            __(
                                'This view has at least this number of '
                                . 'rows. Please refer to %sdocumentation%s.'
                            ),
                            '[doc@cfg_MaxExactCountViews]',
                            '[/doc]'
                        )
                    )
                );
            }
        }

        return [
            $approx_rows,
            $show_superscript,
        ];
    }

    /**
     * Returns the replication status of the table.
     *
     * @param string $table table name
     *
     * @return array
     */
    protected function getReplicationStatus(string $table): array
    {
        $do = $ignored = false;
        if ($GLOBALS['replication_info']['slave']['status']) {
            $nbServSlaveDoDb = count(
                $GLOBALS['replication_info']['slave']['Do_DB']
            );
            $nbServSlaveIgnoreDb = count(
                $GLOBALS['replication_info']['slave']['Ignore_DB']
            );
            $searchDoDBInTruename = array_search(
                $table,
                $GLOBALS['replication_info']['slave']['Do_DB']
            );
            $searchDoDBInDB = array_search(
                $this->db,
                $GLOBALS['replication_info']['slave']['Do_DB']
            );

            $do = (is_string($searchDoDBInTruename) && strlen($searchDoDBInTruename) > 0)
                || (is_string($searchDoDBInDB) && strlen($searchDoDBInDB) > 0)
                || ($nbServSlaveDoDb == 0 && $nbServSlaveIgnoreDb == 0)
                || $this->hasTable(
                    $GLOBALS['replication_info']['slave']['Wild_Do_Table'],
                    $table
                );

            $searchDb = array_search(
                $this->db,
                $GLOBALS['replication_info']['slave']['Ignore_DB']
            );
            $searchTable = array_search(
                $table,
                $GLOBALS['replication_info']['slave']['Ignore_Table']
            );
            $ignored = (is_string($searchTable) && strlen($searchTable) > 0)
                || (is_string($searchDb) && strlen($searchDb) > 0)
                || $this->hasTable(
                    $GLOBALS['replication_info']['slave']['Wild_Ignore_Table'],
                    $table
                );
        }

        return [
            $do,
            $ignored,
        ];
    }

    /**
     * Synchronize favorite tables
     *
     * @param RecentFavoriteTable $favoriteInstance Instance of this class
     * @param string              $user             The user hash
     * @param array               $favoriteTables   Existing favorites
     *
     * @return array
     */
    protected function synchronizeFavoriteTables(
        RecentFavoriteTable $favoriteInstance,
        string $user,
        array $favoriteTables
    ): array {
        $favoriteInstanceTables = $favoriteInstance->getTables();

        if (empty($favoriteInstanceTables)
            && isset($favoriteTables[$user])
        ) {
            foreach ($favoriteTables[$user] as $key => $value) {
                $favoriteInstance->add($value['db'], $value['table']);
            }
        }
        $favoriteTables[$user] = $favoriteInstance->getTables();

        $json = [
            'favoriteTables' => json_encode($favoriteTables),
            'list' => $favoriteInstance->getHtmlList(),
        ];
        $serverId = $GLOBALS['server'];
        // Set flag when localStorage and pmadb(if present) are in sync.
        $_SESSION['tmpval']['favorites_synced'][$serverId] = true;

        return $json;
    }

    /**
     * Function to check if a table is already in favorite list.
     *
     * @param string $currentTable current table
     */
    protected function checkFavoriteTable(string $currentTable): bool
    {
        // ensure $_SESSION['tmpval']['favoriteTables'] is initialized
        RecentFavoriteTable::getInstance('favorite');
        $favoriteTables = $_SESSION['tmpval']['favoriteTables'][$GLOBALS['server']] ?? [];
        foreach ($favoriteTables as $value) {
            if ($value['db'] == $this->db && $value['table'] == $currentTable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find table with truename
     *
     * @param array  $db       DB to look into
     * @param string $truename Table name
     *
     * @return bool
     */
    protected function hasTable(array $db, $truename)
    {
        foreach ($db as $db_table) {
            if ($this->db == $this->replication->extractDbOrTable($db_table)
                && preg_match(
                    '@^' .
                    preg_quote(mb_substr($this->replication->extractDbOrTable($db_table, 'table'), 0, -1), '@') . '@',
                    $truename
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the value set for ENGINE table,
     *
     * @internal param bool $table_is_view whether table is view or not
     *
     * @param array $current_table current table
     * @param int   $sum_size      total table size
     * @param int   $overhead_size overhead size
     *
     * @return array
     */
    protected function getStuffForEngineTypeTable(
        array $current_table,
        $sum_size,
        $overhead_size
    ) {
        $formatted_size = '-';
        $unit = '';
        $formatted_overhead = '';
        $overhead_unit = '';
        $table_is_view = false;

        switch ($current_table['ENGINE']) {
        // MyISAM, ISAM or Heap table: Row count, data size and index size
        // are accurate; data size is accurate for ARCHIVE
            case 'MyISAM':
            case 'ISAM':
            case 'HEAP':
            case 'MEMORY':
            case 'ARCHIVE':
            case 'Aria':
            case 'Maria':
                [
                    $current_table,
                    $formatted_size,
                    $unit,
                    $formatted_overhead,
                    $overhead_unit,
                    $overhead_size,
                    $sum_size,
                ] = $this->getValuesForAriaTable(
                        $current_table,
                        $sum_size,
                        $overhead_size,
                        $formatted_size,
                        $unit,
                        $formatted_overhead,
                        $overhead_unit
                    );
                break;
            case 'InnoDB':
            case 'PBMS':
            case 'TokuDB':
                // InnoDB table: Row count is not accurate but data and index sizes are.
                // PBMS table in Drizzle: TABLE_ROWS is taken from table cache,
                // so it may be unavailable
                [$current_table, $formatted_size, $unit, $sum_size]
                = $this->getValuesForInnodbTable(
                    $current_table,
                    $sum_size
                );
                break;
        // Mysql 5.0.x (and lower) uses MRG_MyISAM
        // and MySQL 5.1.x (and higher) uses MRG_MYISAM
        // Both are aliases for MERGE
            case 'MRG_MyISAM':
            case 'MRG_MYISAM':
            case 'MERGE':
            case 'BerkeleyDB':
                // Merge or BerkleyDB table: Only row count is accurate.
                if ($this->isShowStats) {
                    $formatted_size =  ' - ';
                    $unit          =  '';
                }
                break;
        // for a view, the ENGINE is sometimes reported as null,
        // or on some servers it's reported as "SYSTEM VIEW"
            case null:
            case 'SYSTEM VIEW':
                // possibly a view, do nothing
                break;
            default:
                // Unknown table type.
                if ($this->isShowStats) {
                    $formatted_size =  __('unknown');
                    $unit          =  '';
                }
        } // end switch

        if ($current_table['TABLE_TYPE'] == 'VIEW'
            || $current_table['TABLE_TYPE'] == 'SYSTEM VIEW'
        ) {
            // countRecords() takes care of $cfg['MaxExactCountViews']
            $current_table['TABLE_ROWS'] = $this->dbi
                ->getTable($this->db, $current_table['TABLE_NAME'])
                ->countRecords(true);
            $table_is_view = true;
        }

        return [
            $current_table,
            $formatted_size,
            $unit,
            $formatted_overhead,
            $overhead_unit,
            $overhead_size,
            $table_is_view,
            $sum_size,
        ];
    }

    /**
     * Get values for ARIA/MARIA tables
     *
     * @param array  $current_table      current table
     * @param int    $sum_size           sum size
     * @param int    $overhead_size      overhead size
     * @param int    $formatted_size     formatted size
     * @param string $unit               unit
     * @param int    $formatted_overhead overhead formatted
     * @param string $overhead_unit      overhead unit
     *
     * @return array
     */
    protected function getValuesForAriaTable(
        array $current_table,
        $sum_size,
        $overhead_size,
        $formatted_size,
        $unit,
        $formatted_overhead,
        $overhead_unit
    ) {
        if ($this->dbIsSystemSchema) {
            $current_table['Rows'] = $this->dbi
                ->getTable($this->db, $current_table['Name'])
                ->countRecords();
        }

        if ($this->isShowStats) {
            /** @var int $tblsize */
            $tblsize = $current_table['Data_length']
                + $current_table['Index_length'];
            $sum_size += $tblsize;
            [$formatted_size, $unit] = Util::formatByteDown(
                $tblsize,
                3,
                $tblsize > 0 ? 1 : 0
            );
            if (isset($current_table['Data_free'])
                && $current_table['Data_free'] > 0
            ) {
                [$formatted_overhead, $overhead_unit]
                    = Util::formatByteDown(
                        $current_table['Data_free'],
                        3,
                        ($current_table['Data_free'] > 0 ? 1 : 0)
                    );
                $overhead_size += $current_table['Data_free'];
            }
        }

        return [
            $current_table,
            $formatted_size,
            $unit,
            $formatted_overhead,
            $overhead_unit,
            $overhead_size,
            $sum_size,
        ];
    }

    /**
     * Get values for InnoDB table
     *
     * @param array $current_table current table
     * @param int   $sum_size      sum size
     *
     * @return array
     */
    protected function getValuesForInnodbTable(
        array $current_table,
        $sum_size
    ) {
        $formatted_size = $unit = '';

        if ((in_array($current_table['ENGINE'], ['InnoDB', 'TokuDB'])
            && $current_table['TABLE_ROWS'] < $GLOBALS['cfg']['MaxExactCount'])
            || ! isset($current_table['TABLE_ROWS'])
        ) {
            $current_table['COUNTED'] = true;
            $current_table['TABLE_ROWS'] = $this->dbi
                ->getTable($this->db, $current_table['TABLE_NAME'])
                ->countRecords(true);
        } else {
            $current_table['COUNTED'] = false;
        }

        if ($this->isShowStats) {
            /** @var int $tblsize */
            $tblsize = $current_table['Data_length']
                + $current_table['Index_length'];
            $sum_size += $tblsize;
            [$formatted_size, $unit] = Util::formatByteDown(
                $tblsize,
                3,
                ($tblsize > 0 ? 1 : 0)
            );
        }

        return [
            $current_table,
            $formatted_size,
            $unit,
            $sum_size,
        ];
    }
}
