<?php
/**
 * Hilfe-Center: Anleitungen und FAQ (app/help_content.php), Suche, Support-Anfragen
 * (Tickets) der eigenen Firma mit Verlauf und Ergänzung. Für alle Rollen.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/support_tickets.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];

$content = is_file(__DIR__ . '/app/help_content.php') ? require __DIR__ . '/app/help_content.php' : ['topics' => [], 'faq' => []];
$topics = (array)($content['topics'] ?? []);
$faq = (array)($content['faq'] ?? []);
$topicBySlug = [];
foreach ($topics as $t) {
    $topicBySlug[(string)$t['slug']] = $t;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'ticket_create') {
            $t = ticket_create($ctx, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), (string)($_POST['category'] ?? 'allgemein'), (string)($_POST['page'] ?? '') ?: null);
            flash_set('success', 'Ihre Anfrage wurde übermittelt. Der Support antwortet hier im Hilfe-Center' . (mail_enabled_safe() ? ' und per E-Mail an ' . $ctx['email'] : '') . '.');
            redirect('hilfe.php?ticket=' . urlencode((string)$t['id']) . '#anfragen');
        } elseif ($action === 'ticket_reply') {
            ticket_customer_reply($ctx, (string)($_POST['ticket_id'] ?? ''), (string)($_POST['body'] ?? ''));
            flash_set('success', 'Ihre Ergänzung wurde gespeichert, die Anfrage ist wieder offen.');
            redirect('hilfe.php?ticket=' . urlencode((string)($_POST['ticket_id'] ?? '')) . '#anfragen');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('hilfe.php#anfragen');
}

function mail_enabled_safe(): bool
{
    require_once __DIR__ . '/app/mailer.php';
    return mail_enabled();
}

/** Suche über Titel, Zusammenfassung, Text und FAQ (ohne HTML-Tags). */
$q = trim((string)($_GET['q'] ?? ''));
$activeSlug = (string)($_GET['thema'] ?? '');
$hits = ['topics' => [], 'faq' => []];
if ($q !== '') {
    $needle = mb_strtolower($q);
    foreach ($topics as $t) {
        $hay = mb_strtolower($t['title'] . ' ' . ($t['summary'] ?? '') . ' ' . strip_tags((string)$t['html']));
        if (str_contains($hay, $needle)) {
            $hits['topics'][] = $t;
        }
    }
    foreach ($faq as $f) {
        $hay = mb_strtolower($f['q'] . ' ' . strip_tags((string)$f['a']));
        if (str_contains($hay, $needle)) {
            $hits['faq'][] = $f;
        }
    }
}
$activeTopic = $activeSlug !== '' && isset($topicBySlug[$activeSlug]) ? $topicBySlug[$activeSlug] : null;

$tickets = tickets_for_tenant($tenantId);
$openTicketId = (string)($_GET['ticket'] ?? '');
$openTicket = $openTicketId !== '' ? ticket_load_for_tenant($tenantId, $openTicketId) : null;
$openMessages = $openTicket ? ticket_messages($tenantId, (string)$openTicket['id']) : [];
$fromPage = preg_replace('/[^a-z0-9._-]/i', '', (string)($_GET['von'] ?? ''));

layout_header('Hilfe', $ctx);
?>
<h1>Hilfe-Center</h1>
<p class="page-sub">Anleitungen, Antworten auf häufige Fragen und der direkte Weg zum Support. <a href="#anfragen">Zu meinen Anfragen</a></p>

<div class="help-layout">
    <aside class="help-nav card">
        <form method="get" class="help-search">
            <label for="q" class="hint">Suche in der Hilfe</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="z. B. Webhook, Rücklastschrift, Storno">
            <button type="submit" class="btn btn-sm btn-secondary">Suchen</button>
        </form>
        <h2>Themen</h2>
        <ul class="help-topics">
            <?php foreach ($topics as $t): ?>
                <li><a href="hilfe.php?thema=<?= e($t['slug']) ?>" class="<?= $activeTopic && $activeTopic['slug'] === $t['slug'] ? 'active' : '' ?>"><?= e($t['title']) ?></a></li>
            <?php endforeach; ?>
            <li><a href="hilfe.php#faq">Häufige Fragen</a></li>
            <li><a href="hilfe.php#anfragen">Meine Anfragen (<?= count($tickets) ?>)</a></li>
        </ul>
        <?php if (!$topics): ?><p class="hint">Die Hilfetexte werden gerade ergänzt.</p><?php endif; ?>
    </aside>

    <div class="help-main">
        <?php if ($q !== ''): ?>
        <div class="card">
            <h2>Suchergebnisse für "<?= e($q) ?>"</h2>
            <?php if (!$hits['topics'] && !$hits['faq']): ?>
                <p class="hint">Keine Treffer. Stellen Sie Ihre Frage unten direkt an den Support.</p>
            <?php endif; ?>
            <?php foreach ($hits['topics'] as $t): ?>
                <p><strong><a href="hilfe.php?thema=<?= e($t['slug']) ?>"><?= e($t['title']) ?></a></strong><br><span class="hint"><?= e((string)($t['summary'] ?? '')) ?></span></p>
            <?php endforeach; ?>
            <?php foreach ($hits['faq'] as $f): ?>
                <details class="help-faq-item"><summary><?= e($f['q']) ?></summary><div class="help-answer"><?= $f['a'] ?></div></details>
            <?php endforeach; ?>
        </div>
        <?php elseif ($activeTopic): ?>
        <div class="card help-article">
            <h2><?= e($activeTopic['title']) ?></h2>
            <?php if (!empty($activeTopic['summary'])): ?><p class="hint"><?= e($activeTopic['summary']) ?></p><?php endif; ?>
            <?= $activeTopic['html'] ?>
            <?php $related = array_values(array_filter($faq, static fn(array $f): bool => ($f['topic'] ?? '') === $activeTopic['slug'])); ?>
            <?php if ($related): ?>
                <h3>Häufige Fragen zu diesem Thema</h3>
                <?php foreach ($related as $f): ?>
                    <details class="help-faq-item"><summary><?= e($f['q']) ?></summary><div class="help-answer"><?= $f['a'] ?></div></details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
            <h2>Anleitungen</h2>
            <div class="help-grid">
                <?php foreach ($topics as $t): ?>
                    <a class="help-tile" href="hilfe.php?thema=<?= e($t['slug']) ?>">
                        <strong><?= e($t['title']) ?></strong>
                        <span class="hint"><?= e((string)($t['summary'] ?? '')) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card" id="faq">
            <h2>Häufige Fragen</h2>
            <?php foreach ($faq as $f): ?>
                <details class="help-faq-item"><summary><?= e($f['q']) ?></summary><div class="help-answer"><?= $f['a'] ?></div></details>
            <?php endforeach; ?>
            <?php if (!$faq): ?><p class="hint">Noch keine Einträge.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card" id="anfragen">
            <h2>Frage an den Support</h2>
            <p class="hint">Nicht fündig geworden? Beschreiben Sie Ihr Anliegen, wir antworten hier im Hilfe-Center<?= mail_enabled_safe() ? ' und per E-Mail' : '' ?>.
                Bitte keine API-Schlüssel, Passwörter oder vollständigen IBANs mitschicken, nennen Sie stattdessen Kunden- oder Rechnungsnummern.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ticket_create">
                <input type="hidden" name="page" value="<?= e($fromPage) ?>">
                <div class="form-row">
                    <div>
                        <label for="subject">Betreff</label>
                        <input type="text" id="subject" name="subject" required minlength="5" maxlength="160" placeholder="Kurz, worum es geht">
                    </div>
                    <div>
                        <label for="category">Kategorie</label>
                        <select id="category" name="category">
                            <?php foreach (TICKET_CATEGORIES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label for="body">Ihre Frage</label>
                <textarea id="body" name="body" rows="5" required minlength="20" maxlength="5000" placeholder="Was haben Sie getan, was ist passiert, was haben Sie erwartet? Rechnungs- oder Kundennummer hilft uns."></textarea>
                <div class="form-actions"><button type="submit" class="btn">Anfrage senden</button></div>
            </form>
        </div>

        <?php if ($tickets): ?>
        <div class="card">
            <h2>Meine Anfragen</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Datum</th><th>Betreff</th><th>Kategorie</th><th>Status</th><th>Letzte Nachricht</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><?= format_datetime($t['created_at']) ?></td>
                            <td><?= e($t['subject']) ?><div class="hint">von <?= e($t['user_email']) ?></div></td>
                            <td><?= e(TICKET_CATEGORIES[$t['category']] ?? $t['category']) ?></td>
                            <td><span class="badge <?= $t['status'] === 'open' ? 'badge-info' : ($t['status'] === 'answered' ? 'badge-success' : 'badge-neutral') ?>"><?= e(TICKET_STATUS_LABEL[$t['status']] ?? $t['status']) ?></span></td>
                            <td><?= format_datetime($t['last_message_at']) ?></td>
                            <td><a class="btn btn-sm btn-secondary" href="hilfe.php?ticket=<?= e($t['id']) ?>#anfragen">Verlauf</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($openTicket): ?>
        <div class="card help-thread">
            <h2>Verlauf: <?= e($openTicket['subject']) ?></h2>
            <p class="hint">Status <?= e(TICKET_STATUS_LABEL[$openTicket['status']] ?? $openTicket['status']) ?> · Kategorie <?= e(TICKET_CATEGORIES[$openTicket['category']] ?? $openTicket['category']) ?><?= $openTicket['page'] ? ' · gestellt von Seite ' . e($openTicket['page']) : '' ?></p>
            <?php foreach ($openMessages as $m): ?>
                <div class="help-msg <?= $m['author_type'] === 'support' ? 'help-msg-support' : '' ?>">
                    <div class="hint"><?= $m['author_type'] === 'support' ? 'Support' : e((string)$m['author_email']) ?> · <?= format_datetime($m['created_at']) ?></div>
                    <div><?= nl2br(e($m['body'])) ?></div>
                </div>
            <?php endforeach; ?>
            <form method="post" style="margin-top: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ticket_reply">
                <input type="hidden" name="ticket_id" value="<?= e($openTicket['id']) ?>">
                <label for="reply">Ergänzung oder Rückfrage</label>
                <textarea id="reply" name="body" rows="3" required minlength="5" maxlength="5000"></textarea>
                <div class="form-actions"><button type="submit" class="btn btn-secondary">Senden</button></div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer($ctx); ?>
