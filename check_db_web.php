<?php
/**
 * Web-based database checker for flashcards
 * Upload this to your server: /mod/flashcards/check_db_web.php
 * Access via: https://abcnorsk.no/mod/flashcards/check_db_web.php?secret=YOUR_SECRET_KEY
 *
 * IMPORTANT: Delete this file after use for security!
 */

// Simple security - change this secret key!
$SECRET_KEY = 'flashcards_debug_2025';

if (!isset($_GET['secret']) || $_GET['secret'] !== $SECRET_KEY) {
    die('Access denied. Use ?secret=YOUR_SECRET_KEY');
}

require(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Flashcards Database Check</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .count { font-size: 18px; font-weight: bold; color: #007bff; }
        .warning { color: #ff6b6b; font-weight: bold; }
        .success { color: #51cf66; font-weight: bold; }
        pre { background: #f8f9fa; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>📊 Flashcards Database Check</h1>
    <p><strong>Server:</strong> <?php echo $CFG->wwwroot; ?></p>
    <p><strong>Database:</strong> <?php echo $CFG->dbname; ?> @ <?php echo $CFG->dbhost; ?></p>
    <hr>

    <?php
    // 1. DECKS
    echo '<div class="section">';
    echo '<h2>1. DECKS (mdl_flashcards_decks)</h2>';
    $decks = $DB->get_records_sql("SELECT * FROM {flashcards_decks} ORDER BY id");
    if (empty($decks)) {
        echo '<p class="warning">⚠️ No decks found in database!</p>';
    } else {
        echo '<p class="success">✓ Found ' . count($decks) . ' deck(s)</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Title</th><th>Created By</th><th>Course ID</th><th>Created</th><th>Modified</th></tr>';
        foreach ($decks as $deck) {
            echo '<tr>';
            echo '<td>' . $deck->id . '</td>';
            echo '<td><strong>' . htmlspecialchars($deck->title) . '</strong></td>';
            echo '<td>' . $deck->createdby . '</td>';
            echo '<td>' . $deck->courseid . '</td>';
            echo '<td>' . date('Y-m-d H:i:s', $deck->timecreated) . '</td>';
            echo '<td>' . date('Y-m-d H:i:s', $deck->timemodified) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // 2. CARDS
    echo '<div class="section">';
    echo '<h2>2. CARDS (mdl_flashcards_cards)</h2>';
    $cards = $DB->get_records_sql("SELECT * FROM {flashcards_cards} ORDER BY deckid, id");
    if (empty($cards)) {
        echo '<p class="warning">⚠️ No cards found in database!</p>';
    } else {
        echo '<p class="success">✓ Found ' . count($cards) . ' card(s)</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Deck ID</th><th>Card ID</th><th>Owner</th><th>Scope</th><th>Payload Preview</th><th>Created</th></tr>';
        foreach ($cards as $card) {
            $payload = json_decode($card->payload, true);
            $preview = isset($payload['text']) ? htmlspecialchars(substr($payload['text'], 0, 50)) :
                       (isset($payload['front']) ? htmlspecialchars(substr($payload['front'], 0, 50)) : '[no text]');
            echo '<tr>';
            echo '<td>' . $card->id . '</td>';
            echo '<td><strong>' . $card->deckid . '</strong></td>';
            echo '<td>' . htmlspecialchars($card->cardid) . '</td>';
            echo '<td>' . ($card->ownerid ?? '<em>NULL</em>') . '</td>';
            echo '<td>' . $card->scope . '</td>';
            echo '<td>' . $preview . '...</td>';
            echo '<td>' . date('Y-m-d H:i:s', $card->timecreated) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // 3. PROGRESS
    echo '<div class="section">';
    echo '<h2>3. PROGRESS (mdl_flashcards_progress)</h2>';
    $total_progress = $DB->count_records('flashcards_progress');
    $progress = $DB->get_records_sql("SELECT * FROM {flashcards_progress} ORDER BY userid, deckid, cardid LIMIT 50");

    echo '<p class="success">✓ Found ' . $total_progress . ' progress record(s) (showing first 50)</p>';

    if (!empty($progress)) {
        echo '<table>';
        echo '<tr><th>ID</th><th>Activity ID</th><th>User ID</th><th>Deck ID</th><th>Card ID</th><th>Step</th><th>Due</th><th>Added</th></tr>';
        foreach ($progress as $p) {
            $is_due = $p->due <= time();
            $due_class = $is_due ? 'success' : '';
            echo '<tr>';
            echo '<td>' . $p->id . '</td>';
            echo '<td>' . $p->flashcardsid . '</td>';
            echo '<td>' . $p->userid . '</td>';
            echo '<td><strong>' . htmlspecialchars($p->deckid) . '</strong></td>';
            echo '<td>' . htmlspecialchars($p->cardid) . '</td>';
            echo '<td>' . $p->step . '</td>';
            echo '<td class="' . $due_class . '">' . date('Y-m-d H:i:s', $p->due) . ($is_due ? ' ✓' : ' ⏳') . '</td>';
            echo '<td>' . date('Y-m-d H:i:s', $p->addedat) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // 4. MEDIA FILES
    echo '<div class="section">';
    echo '<h2>4. MEDIA FILES (mdl_files)</h2>';
    $total_files = $DB->count_records_select('files', "component = 'mod_flashcards' AND filename != '.'");
    $files = $DB->get_records_sql("
        SELECT * FROM {files}
        WHERE component = 'mod_flashcards' AND filename != '.'
        ORDER BY timecreated DESC
        LIMIT 50
    ");

    if ($total_files == 0) {
        echo '<p class="warning">⚠️ No media files found!</p>';
    } else {
        echo '<p class="success">✓ Found ' . $total_files . ' file(s) (showing first 50)</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Context</th><th>Area</th><th>Item ID</th><th>Filename</th><th>Size</th><th>Created</th></tr>';
        foreach ($files as $file) {
            echo '<tr>';
            echo '<td>' . $file->id . '</td>';
            echo '<td>' . $file->contextid . '</td>';
            echo '<td>' . $file->filearea . '</td>';
            echo '<td>' . $file->itemid . '</td>';
            echo '<td><strong>' . htmlspecialchars($file->filename) . '</strong></td>';
            echo '<td>' . round($file->filesize / 1024, 2) . ' KB</td>';
            echo '<td>' . date('Y-m-d H:i:s', $file->timecreated) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // 5. SUMMARY BY DECK
    echo '<div class="section">';
    echo '<h2>5. SUMMARY BY DECK</h2>';
    if (!empty($decks)) {
        echo '<table>';
        echo '<tr><th>Deck ID</th><th>Title</th><th>Cards</th><th>Progress Records</th><th>Due Now</th></tr>';
        foreach ($decks as $deck) {
            $card_count = $DB->count_records('flashcards_cards', ['deckid' => $deck->id]);
            $progress_count = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {flashcards_progress} WHERE deckid = :deckid",
                ['deckid' => (string)$deck->id]
            );
            $due_count = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {flashcards_progress} WHERE deckid = :deckid AND due <= :now",
                ['deckid' => (string)$deck->id, 'now' => time()]
            );
            echo '<tr>';
            echo '<td><strong>' . $deck->id . '</strong></td>';
            echo '<td>' . htmlspecialchars($deck->title) . '</td>';
            echo '<td class="count">' . $card_count . '</td>';
            echo '<td class="count">' . $progress_count . '</td>';
            echo '<td class="count ' . ($due_count > 0 ? 'success' : '') . '">' . $due_count . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // 6. RECOMMENDATIONS
    echo '<div class="section">';
    echo '<h2>6. RECOMMENDATIONS</h2>';

    if (empty($decks)) {
        echo '<p class="warning">🔴 No decks found! The database appears to be empty.</p>';
        echo '<p>This could mean:</p>';
        echo '<ul>';
        echo '<li>Plugin was uninstalled and tables were dropped</li>';
        echo '<li>Database migration failed</li>';
        echo '<li>You are looking at a different database than expected</li>';
        echo '</ul>';
    } elseif (empty($cards)) {
        echo '<p class="warning">🟡 Decks exist but no cards found!</p>';
        echo '<p>Cards may have been deleted or not yet created.</p>';
    } else {
        echo '<p class="success">✓ Database looks healthy!</p>';

        // Check for orphaned progress
        $orphaned = $DB->get_records_sql("
            SELECT p.* FROM {flashcards_progress} p
            LEFT JOIN {flashcards_cards} c ON c.deckid = p.deckid AND c.cardid = p.cardid
            WHERE c.id IS NULL
            LIMIT 10
        ");
        if (!empty($orphaned)) {
            echo '<p class="warning">⚠️ Found ' . count($orphaned) . ' orphaned progress records (progress without matching cards)</p>';
        }
    }
    echo '</div>';
    ?>

    <hr>
    <p><em>Generated: <?php echo date('Y-m-d H:i:s'); ?></em></p>
    <p style="color: red;"><strong>⚠️ IMPORTANT: Delete this file after use for security!</strong></p>
</body>
</html>
