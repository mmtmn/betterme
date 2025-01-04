<?php
date_default_timezone_set('UTC');
$db_file = __DIR__ . '/db.txt';

// -- Handle AJAX requests ----------------------------------------------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // 1) Save the quit date/time
    if ($action === 'saveQuitTime' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $quitDateTime = $_POST['quitDateTime'] ?? null; // e.g. "2025-01-04T10:30"
        if ($quitDateTime) {
            // Overwrite or store just once (depending on how you want it to behave)
            // We'll store in the file as "QUIT|<timestamp>"
            $quitLine = "QUIT|" . $quitDateTime . "\n";
            
            // Rewrite the file’s first line or just append
            // For simplicity, we’ll remove any existing QUIT line and then append a new one.
            $lines = file_exists($db_file) ? file($db_file) : [];
            // Filter out lines that begin with "QUIT|"
            $filteredLines = [];
            foreach ($lines as $l) {
                if (strpos($l, "QUIT|") !== 0) {
                    $filteredLines[] = $l;
                }
            }
            // Add the new QUIT line at top
            array_unshift($filteredLines, $quitLine);
            file_put_contents($db_file, implode("", $filteredLines));
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // 2) Save daily smoking count (plus/minus updates)
    if ($action === 'saveDailySmoking' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $date = (new DateTime())->format('Y-m-d');
        $count = $_POST['count'] ?? 0;

        // We'll store as "DAILY|YYYY-MM-DD|count"
        $line = "DAILY|" . $date . "|" . $count . "\n";

        // If there's already an entry for today, overwrite it. Otherwise, append.
        $lines = file_exists($db_file) ? file($db_file) : [];
        $found = false;
        foreach ($lines as $idx => $l) {
            if (strpos($l, "DAILY|") === 0) {
                $parts = explode("|", $l);
                if (isset($parts[1]) && $parts[1] === $date) {
                    // Overwrite
                    $lines[$idx] = $line;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            $lines[] = $line;
        }
        file_put_contents($db_file, implode("", $lines));
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // 3) Fetch stats: hours since quit, next milestone progress, daily count
    if ($action === 'stats') {
        $data = parseQuitAndDaily($db_file);
        $quitTimestamp = $data['quitTime'];
        $dailyCount    = $data['dailyCount'];
        
        // If we have a quit timestamp, figure out how many hours have passed
        $hoursSinceQuit = null;
        if ($quitTimestamp) {
            $quitDateObj = new DateTime($quitTimestamp);
            $now         = new DateTime();
            $interval    = $quitDateObj->diff($now);
            $hoursSinceQuit = $interval->days * 24 + $interval->h + ($interval->i / 60.0);
        }

        // Identify the milestone info (we’ll track in minutes for better granularity)
        $milestones = getMilestones(); // array of [minutes => label, 'desc' => ...]
        $progressData = computeProgress($hoursSinceQuit, $milestones);

        echo json_encode([
            'hoursSinceQuit' => $hoursSinceQuit ? round($hoursSinceQuit, 2) : null,
            'quitDateTime'   => $quitTimestamp,
            'dailyCount'     => $dailyCount,
            'progress'       => $progressData
        ]);
        exit;
    }
    exit;
}

// -- Helper Functions --------------------------------------------------------

/**
 * parseQuitAndDaily($filename) 
 *   -> returns [
 *        'quitTime'   => (string|null),
 *        'dailyCount' => (int), // for today's date
 *      ]
 */
function parseQuitAndDaily($filename) {
    if (!file_exists($filename)) {
        return ['quitTime' => null, 'dailyCount' => 0];
    }

    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $quitTime   = null;
    $today      = (new DateTime())->format('Y-m-d');
    $dailyCount = 0;

    foreach ($lines as $l) {
        $parts = explode("|", $l);
        
        // QUIT line
        if (count($parts) === 2 && $parts[0] === 'QUIT') {
            $quitTime = $parts[1]; // e.g. "2025-01-04T10:30"
        }

        // DAILY line
        if (count($parts) === 3 && $parts[0] === 'DAILY') {
            if ($parts[1] === $today) {
                $dailyCount = (int)$parts[2];
            }
        }
    }

    return [
        'quitTime'   => $quitTime,
        'dailyCount' => $dailyCount
    ];
}

/**
 * We define our milestone data in minutes:
 *  - 20 min, 8 hr, 12 hr, 24 hr, 48 hr, 72 hr, 1 week, 2 weeks, etc.
 */
function getMilestones() {
    return [
        // minutes => [ 'label' => '', 'desc' => '' ]
        20 => [
            'label' => '20 Minutes',
            'desc'  => 'Your heart rate and blood pressure are dropping back to normal levels.',
        ],
        (8 * 60) => [
            'label' => '8 Hours',
            'desc'  => 'Carbon monoxide levels in the blood decrease, improving oxygen delivery.',
        ],
        (12 * 60) => [
            'label' => '12 Hours',
            'desc'  => 'Carbon monoxide levels return to normal, more oxygen in your blood!',
        ],
        (24 * 60) => [
            'label' => '24 Hours',
            'desc'  => 'Your risk of heart attack begins to decrease as your body cleanses itself of nicotine.',
        ],
        (48 * 60) => [
            'label' => '48 Hours',
            'desc'  => 'Taste and smell start to improve as nerve endings recover.',
        ],
        (72 * 60) => [
            'label' => '72 Hours',
            'desc'  => 'Bronchial tubes relax, making breathing easier. Energy levels improve.',
        ],
        (7 * 24 * 60) => [
            'label' => '1 Week',
            'desc'  => 'Withdrawal symptoms begin to subside, mental clarity increases!',
        ],
        (14 * 24 * 60) => [
            'label' => '2 Weeks',
            'desc'  => 'Circulation improves, and your lung function continues to recover.',
        ],
        (30 * 24 * 60) => [
            'label' => '1 Month',
            'desc'  => 'Coughing and shortness of breath decrease significantly.',
        ],
        (3 * 30 * 24 * 60) => [
            'label' => '3 Months',
            'desc'  => 'Your circulation and lung function have made remarkable progress!',
        ],
        (6 * 30 * 24 * 60) => [
            'label' => '6 Months',
            'desc'  => 'You’re breathing easier and have fewer lung infections.',
        ],
        (12 * 30 * 24 * 60) => [
            'label' => '1 Year',
            'desc'  => 'Your risk of heart disease is about half that of a smoker.',
        ],
        (5 * 365 * 24 * 60) => [
            'label' => '5 Years',
            'desc'  => 'Stroke risk is now similar to that of a non-smoker.',
        ],
        (10 * 365 * 24 * 60) => [
            'label' => '10 Years',
            'desc'  => 'Lung cancer risk is about half that of someone who still smokes.',
        ],
        (15 * 365 * 24 * 60) => [
            'label' => '15 Years',
            'desc'  => 'Your risk of heart disease is the same as someone who never smoked!',
        ],
    ];
}

/**
 * computeProgress($hoursSinceQuit, $milestones)
 *   -> Returns the current milestone, next milestone, plus a progress fraction for the progress bar.
 */
function computeProgress($hoursSinceQuit, $milestones) {
    if ($hoursSinceQuit === null) {
        // Means user hasn’t set a quit time yet
        return [
            'currentLabel'   => null,
            'currentDesc'    => null,
            'nextLabel'      => null,
            'nextDesc'       => null,
            'progressRatio'  => 0,
            'minutesElapsed' => 0,
            'nextMinutes'    => 0
        ];
    }

    $minutesElapsed = (int)round($hoursSinceQuit * 60);

    // Sort milestone keys in ascending order
    $sortedMilestones = $milestones;
    ksort($sortedMilestones);

    // We’ll step through to find which milestone we’ve reached and which is next.
    $previousKey = 0;
    $previous = ['label' => 'Just Quit', 'desc' => 'Congratulations on taking this step!'];
    $next = null;

    foreach ($sortedMilestones as $mMinutes => $info) {
        if ($minutesElapsed < $mMinutes) {
            $next = [
                'minutes' => $mMinutes,
                'label'   => $info['label'],
                'desc'    => $info['desc']
            ];
            break;
        } else {
            $previousKey = $mMinutes;
            $previous = $info;
        }
    }

    // If we’ve surpassed the last milestone, there is no next milestone in our list
    if (!$next) {
        end($sortedMilestones);
        $lastKey = key($sortedMilestones);
        if ($minutesElapsed >= $lastKey) {
            $previousKey = $lastKey;
            $previous = current($sortedMilestones);
            $next = null;
        }
    }

    // Calculate progress ratio:
    // If we have a next milestone, ratio = (minutesElapsed - previousKey) / (nextKey - previousKey)
    $progressRatio = 1.0;
    $nextMinutes   = 0;

    if ($next) {
        $nextMinutes = $next['minutes'];
        $delta = $nextMinutes - $previousKey;
        if ($delta > 0) {
            $progressRatio = ($minutesElapsed - $previousKey) / $delta;
            if ($progressRatio < 0) $progressRatio = 0;
            if ($progressRatio > 1) $progressRatio = 1;
        }
    }

    return [
        'currentLabel'   => $previous['label'],
        'currentDesc'    => $previous['desc'],
        'nextLabel'      => $next ? $next['label'] : null,
        'nextDesc'       => $next ? $next['desc'] : null,
        'progressRatio'  => round($progressRatio, 3),
        'minutesElapsed' => $minutesElapsed,
        'nextMinutes'    => $nextMinutes
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>Quit Smoking Tracker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background: #f7f7f7;
            margin: 0; padding: 0;
        }
        .container {
            background: #fff;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            margin: 10px;
            padding: 8px 16px;
            background: #007BFF;
            color: #fff;
            border: none; cursor: pointer;
            border-radius: 4px;
        }
        /* Quit Time Form */
        #quitFormSection, #smokeFormSection {
            margin: 20px 0;
            text-align: left;
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
        }
        label {
            font-weight: bold;
        }
        input[type="datetime-local"] {
            padding: 6px; font-size: 14px;
        }
        .plusminus-btns {
            display: flex; gap: 10px;
            justify-content: center;
            align-items: center;
            margin-top: 10px;
        }
        .progress-container {
            margin-top: 20px;
        }
        .progress-bar-bg {
            width: 100%; height: 20px;
            background: #ccc; border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%; background: #28a745;
            width: 0%; /* dynamically set via JS */
            transition: width 0.5s ease;
        }
        .milestone-text {
            margin-top: 10px;
        }
        .message-box {
            margin-top: 15px; 
            background: #e9f7ef; 
            padding: 10px; 
            border: 1px solid #c3e6cb; 
            color: #155724; 
            border-radius: 4px;
            display: inline-block;
            max-width: 100%;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Quit Smoking Tracker</h1>

    <!-- 1) Quit Time Section -->
    <section id="quitFormSection">
        <h2>Set or Update Quit Time</h2>
        <form id="quitForm">
            <label for="quitDateTime">I stopped smoking on:</label><br/>
            <input type="datetime-local" id="quitDateTime" name="quitDateTime" required/>
            <button type="submit" class="btn">Save Quit Time</button>
        </form>
    </section>

    <!-- 2) Daily Smoking Tracker (plus/minus) -->
    <section id="smokeFormSection">
        <h2>Today's Smoking</h2>
        <div class="plusminus-btns">
            <button id="minusBtn" class="btn">-</button>
            <span id="smokeCountDisplay">0</span>
            <button id="plusBtn" class="btn">+</button>
        </div>
        <p>If this stays at 0 all day, that’s fantastic progress!</p>
    </section>

    <!-- 3) Progress & Milestones -->
    <section class="progress-container">
        <h2>Health Milestones</h2>
        <p id="currentMilestone" style="font-weight: bold;"></p>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progressBarFill" style="width:0%;"></div>
        </div>
        <div class="milestone-text">
            Next: <span id="nextMilestoneLabel"></span>
        </div>
        <div class="message-box" id="milestoneMessage"></div>
    </section>
</div>

<script>
let currentCount = 0;

// -------------- QUIT FORM HANDLER --------------
document.getElementById('quitForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const quitDateTime = document.getElementById('quitDateTime').value; 
    if (!quitDateTime) return;

    const formData = new FormData();
    formData.append('quitDateTime', quitDateTime);

    fetch('?action=saveQuitTime', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(d => {
        updateStats();
    });
});

// -------------- DAILY SMOKING PLUS/MINUS --------------
document.getElementById('minusBtn').addEventListener('click', function() {
    if (currentCount > 0) {
        currentCount--;
        saveDailySmoking(currentCount);
    }
});
document.getElementById('plusBtn').addEventListener('click', function() {
    currentCount++;
    saveDailySmoking(currentCount);
});

function saveDailySmoking(count) {
    const formData = new FormData();
    formData.append('count', count);

    fetch('?action=saveDailySmoking', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('smokeCountDisplay').textContent = count;
    });
}

// -------------- LOAD & UPDATE STATS --------------
function updateStats() {
    fetch('?action=stats')
    .then(r => r.json())
    .then(d => {
        // hoursSinceQuit, quitDateTime, dailyCount, progress{}
        currentCount = d.dailyCount || 0;
        document.getElementById('smokeCountDisplay').textContent = currentCount;

        // If no quit time is set, handle that
        if (!d.quitDateTime) {
            document.getElementById('currentMilestone').textContent = "No quit time set yet.";
            document.getElementById('progressBarFill').style.width = "0%";
            document.getElementById('nextMilestoneLabel').textContent = "--";
            document.getElementById('milestoneMessage').textContent = "Please set your quit date/time above.";
            return;
        }

        // We have a quit time, so let's show milestone info
        let p = d.progress;
        document.getElementById('currentMilestone').textContent = p.currentLabel;
        if (!p.nextLabel) {
            // Surpassed the last milestone in our list
            document.getElementById('nextMilestoneLabel').textContent = "You’ve reached the final milestone in our tracker!";
        } else {
            document.getElementById('nextMilestoneLabel').textContent = p.nextLabel;
        }
        document.getElementById('milestoneMessage').textContent = p.currentDesc;

        // Progress bar
        let percent = p.progressRatio * 100;
        document.getElementById('progressBarFill').style.width = percent + "%";
    });
}

// On page load:
document.addEventListener('DOMContentLoaded', () => {
    updateStats();
});
</script>
</body>
</html>
