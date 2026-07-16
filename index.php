<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/storage/feedback.json';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function feedbacks(): array
{
    if (!is_file(DATA_FILE)) {
        return [];
    }

    $contents = file_get_contents(DATA_FILE);
    $records = $contents === false ? [] : json_decode($contents, true);
    return is_array($records) ? $records : [];
}

function saveFeedbacks(array $records): bool
{
    $directory = dirname(DATA_FILE);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    return file_put_contents(DATA_FILE, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $message): never
{
    header('Location: index.php?notice=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid form request. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    $records = feedbacks();

    if ($action === 'submit') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'General');
        $message = trim((string) ($_POST['message'] ?? ''));
        $allowedCategories = ['General', 'Idea', 'Bug report', 'Compliment'];

        if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($category, $allowedCategories, true)) {
            redirect('Please provide your name, a valid email address, and feedback.');
        }
        if (mb_strlen($message) > 2000 || mb_strlen($name) > 100) {
            redirect('Please keep your name and feedback within the allowed length.');
        }

        array_unshift($records, [
            'id' => bin2hex(random_bytes(6)),
            'name' => $name,
            'email' => $email,
            'category' => $category,
            'message' => $message,
            'status' => 'New',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        saveFeedbacks($records);
        redirect('Thank you — your feedback has been received.');
    }

    if ($action === 'update_status') {
        $id = (string) ($_POST['id'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $allowedStatuses = ['New', 'In review', 'Resolved'];
        if (in_array($status, $allowedStatuses, true)) {
            foreach ($records as &$record) {
                if (($record['id'] ?? '') === $id) {
                    $record['status'] = $status;
                    saveFeedbacks($records);
                    redirect('Feedback status updated.');
                }
            }
            unset($record);
        }
        redirect('Unable to update that feedback item.');
    }
}

$records = feedbacks();
$filter = (string) ($_GET['filter'] ?? 'All');
$validFilters = ['All', 'New', 'In review', 'Resolved'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'All';
}
$visibleRecords = $filter === 'All' ? $records : array_values(array_filter($records, static fn (array $record): bool => ($record['status'] ?? '') === $filter));
$counts = array_fill_keys($validFilters, 0);
$counts['All'] = count($records);
foreach ($records as $record) {
    $status = $record['status'] ?? 'New';
    if (isset($counts[$status])) $counts[$status]++;
}
$notice = (string) ($_GET['notice'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pulse — Feedback hub</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="index.php"><span class="brand-mark">P</span> pulse</a>
      <span class="tagline">Better products, one signal at a time.</span>
    </header>

    <section class="hero">
      <div>
        <p class="eyebrow">Customer feedback hub</p>
        <h1>Every thoughtful note<br>moves us forward.</h1>
        <p class="intro">Share what’s working, what isn’t, or the idea you can’t stop thinking about. We read every response.</p>
      </div>
      <div class="hero-note"><span>✦</span><strong>Your voice matters.</strong><br>Clear feedback helps us build with care.</div>
    </section>

    <?php if ($notice !== ''): ?>
      <div class="notice" role="status"><?= escape($notice) ?></div>
    <?php endif; ?>

    <section class="workspace">
      <article class="card form-card">
        <div class="section-heading"><p class="eyebrow">Send feedback</p><h2>What’s on your mind?</h2></div>
        <form method="post" class="feedback-form">
          <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="submit">
          <div class="field-row">
            <label>Your name<input name="name" maxlength="100" required autocomplete="name" placeholder="Alex Morgan"></label>
            <label>Email address<input type="email" name="email" required autocomplete="email" placeholder="alex@example.com"></label>
          </div>
          <label>Feedback type<select name="category"><option>General</option><option>Idea</option><option>Bug report</option><option>Compliment</option></select></label>
          <label>Your feedback<textarea name="message" maxlength="2000" required placeholder="Tell us about your experience…"></textarea></label>
          <button type="submit">Send feedback <span>→</span></button>
        </form>
      </article>

      <aside class="card dashboard">
        <div class="section-heading"><p class="eyebrow">Team dashboard</p><h2>Inbox at a glance</h2></div>
        <div class="stats">
          <div><b><?= $counts['All'] ?></b><span>All feedback</span></div><div><b><?= $counts['New'] ?></b><span>New</span></div><div><b><?= $counts['In review'] ?></b><span>In review</span></div><div><b><?= $counts['Resolved'] ?></b><span>Resolved</span></div>
        </div>
        <nav class="filters" aria-label="Feedback filters">
          <?php foreach ($validFilters as $item): ?><a class="<?= $filter === $item ? 'active' : '' ?>" href="?filter=<?= rawurlencode($item) ?>"><?= escape($item) ?> <span><?= $counts[$item] ?></span></a><?php endforeach; ?>
        </nav>
        <div class="items">
          <?php if (!$visibleRecords): ?><p class="empty">No feedback here yet. Your customer notes will appear in this inbox.</p><?php endif; ?>
          <?php foreach ($visibleRecords as $record): ?>
            <article class="feedback-item">
              <div class="item-top"><span class="category"><?= escape($record['category']) ?></span><time><?= escape(date('M j, Y', strtotime($record['created_at']))) ?></time></div>
              <p><?= nl2br(escape($record['message'])) ?></p>
              <footer><span><strong><?= escape($record['name']) ?></strong> · <?= escape($record['email']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= escape($record['id']) ?>"><select name="status" onchange="this.form.submit()" aria-label="Update status for <?= escape($record['name']) ?>"><?php foreach (['New', 'In review', 'Resolved'] as $status): ?><option <?= $record['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></form></footer>
            </article>
          <?php endforeach; ?>
        </div>
      </aside>
    </section>
  </main>
</body>
</html>
