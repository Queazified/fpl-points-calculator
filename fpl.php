<?php
/**
 * Fantasy Premier League Real-Time Leaderboard
 * 
 * Displays live FPL league standings with accurate points from official APIs.
 * Supports HTML, JSON, and CSV output formats with intelligent caching.
 * 
 * @author Queazified
 * @license MIT
 * @version 1.0.0
 */

// Configuration
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_DURATION', 240); // 4 minutes in seconds
define('API_TIMEOUT', 15); // API request timeout in seconds
define('FPL_API_BASE', 'https://fantasy.premierleague.com/api');
define('REFRESH_COOLDOWN', 30); // 30 seconds cooldown for forced refresh

// Initialize
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors in production

/**
 * Check if refresh is allowed (server-side rate limiting)
 */
function isRefreshAllowed($leagueId) {
    $sessionKey = 'last_refresh_' . $leagueId;
    
    if (isset($_SESSION[$sessionKey])) {
        $timeSinceRefresh = time() - $_SESSION[$sessionKey];
        if ($timeSinceRefresh < REFRESH_COOLDOWN) {
            return [
                'allowed' => false,
                'remaining' => REFRESH_COOLDOWN - $timeSinceRefresh
            ];
        }
    }
    
    return ['allowed' => true, 'remaining' => 0];
}

/**
 * Record refresh timestamp
 */
function recordRefresh($leagueId) {
    $_SESSION['last_refresh_' . $leagueId] = time();
}

/**
 * Ensure cache directory exists
 */
function ensureCacheDirectory() {
    if (!file_exists(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
}

/**
 * Fetch data from URL using cURL with cache-busting
 */
function fetchData($url) {
    $ch = curl_init();
    
    // Add timestamp to prevent caching
    $separator = strpos($url, '?') !== false ? '&' : '?';
    $url .= $separator . '_=' . time();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: $httpCode");
    }
    
    return json_decode($response, true);
}

/**
 * Get cache file path for a league
 */
function getCacheFilePath($leagueId) {
    return CACHE_DIR . '/fpl_cache_' . $leagueId . '.json';
}

/**
 * Check if cache is valid
 */
function isCacheValid($leagueId) {
    $cacheFile = getCacheFilePath($leagueId);
    
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $cacheAge = time() - filemtime($cacheFile);
    return $cacheAge < CACHE_DURATION;
}

/**
 * Get data from cache
 */
function getFromCache($leagueId) {
    $cacheFile = getCacheFilePath($leagueId);
    
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $data = json_decode(file_get_contents($cacheFile), true);
    return $data;
}

/**
 * Save data to cache
 */
function saveToCache($leagueId, $data) {
    $cacheFile = getCacheFilePath($leagueId);
    $data['cached_at'] = time();
    file_put_contents($cacheFile, json_encode($data));
}

/**
 * Fetch current gameweek from bootstrap-static API
 */
function getCurrentGameweek() {
    $data = fetchData(FPL_API_BASE . '/bootstrap-static/');
    
    foreach ($data['events'] as $event) {
        if ($event['is_current']) {
            return $event['id'];
        }
    }
    
    // Fallback: find the latest finished or current gameweek
    foreach ($data['events'] as $event) {
        if (!$event['finished']) {
            return $event['id'];
        }
    }
    
    return 1;
}

/**
 * Fetch league data and standings with accurate points
 */
function fetchLeagueData($leagueId, $forceRefresh = false) {
    ensureCacheDirectory();
    
    // Check cache first (unless forced refresh)
    if (!$forceRefresh && isCacheValid($leagueId)) {
        return getFromCache($leagueId);
    }
    
    try {
        // Fetch current gameweek
        $currentGameweek = getCurrentGameweek();
        
        // Fetch league standings
        $leagueData = fetchData(FPL_API_BASE . "/leagues-classic/$leagueId/standings/");
        
        if (!isset($leagueData['standings']['results'])) {
            throw new Exception("Invalid league data received");
        }
        
        $standings = [];
        
        // Fetch individual entry data for accurate points
        foreach ($leagueData['standings']['results'] as $entry) {
            $entryId = $entry['entry'];
            $entryData = fetchData(FPL_API_BASE . "/entry/$entryId/");
            
            $standings[] = [
                'entry_id' => $entryId,
                'entry_name' => $entry['entry_name'],
                'player_name' => $entry['player_name'],
                'total_points' => (int)$entryData['summary_overall_points'], // Accurate total points
                'event_points' => (int)$entryData['summary_event_points'],
                'overall_rank' => (int)$entryData['summary_overall_rank']
            ];
        }
        
        // Sort by total points (descending)
        usort($standings, function($a, $b) {
            return $b['total_points'] - $a['total_points'];
        });
        
        // Reassign ranks based on sorted points
        $rank = 1;
        foreach ($standings as &$standing) {
            $standing['rank'] = $rank++;
        }
        
        $result = [
            'league' => [
                'id' => (int)$leagueId,
                'name' => $leagueData['league']['name'],
                'current_gameweek' => $currentGameweek
            ],
            'standings' => $standings,
            'last_updated' => date('Y-m-d H:i:s')
        ];
        
        // Save to cache
        saveToCache($leagueId, $result);
        
        return $result;
        
    } catch (Exception $e) {
        // If cache exists, return stale data with error note
        if (file_exists(getCacheFilePath($leagueId))) {
            $cached = getFromCache($leagueId);
            $cached['error'] = 'Using cached data due to API error: ' . $e->getMessage();
            return $cached;
        }
        throw $e;
    }
}

/**
 * Get cache age in human-readable format
 */
function getCacheAge($leagueId) {
    $cacheFile = getCacheFilePath($leagueId);
    
    if (!file_exists($cacheFile)) {
        return 'Never';
    }
    
    $age = time() - filemtime($cacheFile);
    
    if ($age < 60) {
        return $age . ' seconds ago';
    } elseif ($age < 3600) {
        return floor($age / 60) . ' minute' . (floor($age / 60) != 1 ? 's' : '') . ' ago';
    } else {
        return floor($age / 3600) . ' hour' . (floor($age / 3600) != 1 ? 's' : '') . ' ago';
    }
}

/**
 * Output data as JSON
 */
function outputJSON($data) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Output data as CSV
 */
function outputCSV($data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fpl_league_' . $data['league']['id'] . '_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, ['Rank', 'Team Name', 'Manager Name', 'Total Points', 'Gameweek Points', 'Overall Rank']);
    
    // Write data
    foreach ($data['standings'] as $standing) {
        fputcsv($output, [
            $standing['rank'],
            $standing['entry_name'],
            $standing['player_name'],
            $standing['total_points'],
            $standing['event_points'],
            $standing['overall_rank']
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * Output data as HTML
 */
function outputHTML($data, $cacheAge) {
    $leagueName = htmlspecialchars($data['league']['name']);
    $currentGW = $data['league']['current_gameweek'];
    $lastUpdated = $data['last_updated'];
    $leagueId = $data['league']['id'];
    $errorMessage = isset($data['error']) ? $data['error'] : null;
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $leagueName; ?> - FPL Leaderboard</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>⚽</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .header h1 {
            color: #37003c;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .live-indicator {
            display: inline-block;
            background: #00ff87;
            color: #37003c;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: bold;
            font-size: 0.9em;
            animation: pulse 2s infinite;
            margin: 15px 0;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .gameweek-info {
            font-size: 1.2em;
            color: #666;
            margin-top: 10px;
        }
        
        .last-updated {
            font-size: 0.9em;
            color: #999;
            margin-top: 10px;
        }
        
        .error-message {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .leaderboard {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #37003c;
            color: white;
        }
        
        th {
            padding: 20px 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85em;
            letter-spacing: 0.5px;
        }
        
        th.center, td.center {
            text-align: center;
        }
        
        th.right, td.right {
            text-align: right;
        }
        
        tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }
        
        tbody tr:hover {
            background: #f8f9ff;
            transform: translateX(5px);
        }
        
        td {
            padding: 18px 15px;
        }
        
        .rank {
            font-weight: bold;
            font-size: 1.2em;
            width: 60px;
        }
        
        .rank-1 { color: #FFD700; } /* Gold */
        .rank-2 { color: #C0C0C0; } /* Silver */
        .rank-3 { color: #CD7F32; } /* Bronze */
        
        .rank-medal {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            text-align: center;
            border-radius: 50%;
            font-size: 0.9em;
        }
        
        .rank-1 .rank-medal {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: white;
        }
        
        .rank-2 .rank-medal {
            background: linear-gradient(135deg, #C0C0C0, #A0A0A0);
            color: white;
        }
        
        .rank-3 .rank-medal {
            background: linear-gradient(135deg, #CD7F32, #8B4513);
            color: white;
        }
        
        .team-info {
            display: flex;
            flex-direction: column;
        }
        
        .team-name {
            font-weight: 600;
            font-size: 1.1em;
            color: #37003c;
            margin-bottom: 3px;
        }
        
        .manager-name {
            color: #999;
            font-size: 0.9em;
        }
        
        .total-points {
            font-weight: bold;
            font-size: 1.3em;
            color: #37003c;
        }
        
        .gw-points {
            color: #00ff87;
            font-weight: 600;
        }
        
        .overall-rank {
            color: #666;
        }
        
        .refresh-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #37003c;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .refresh-button:hover:not(:disabled) {
            background: #00ff87;
            color: #37003c;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }
        
        .refresh-button:active:not(:disabled) {
            transform: translateY(-1px);
        }
        
        .refresh-button:disabled {
            background: #999;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header h1 {
                font-size: 1.8em;
            }
            
            .gameweek-info {
                font-size: 1em;
            }
            
            th, td {
                padding: 12px 8px;
                font-size: 0.9em;
            }
            
            .manager-name,
            .overall-rank-col {
                display: none;
            }
            
            .refresh-button {
                bottom: 15px;
                right: 15px;
                padding: 12px 20px;
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo $leagueName; ?></h1>
            <div class="live-indicator">● LIVE</div>
            <div class="gameweek-info">Gameweek <?php echo $currentGW; ?></div>
            <div class="last-updated">
                Last updated: <?php echo $lastUpdated; ?> (<?php echo $cacheAge; ?>)
            </div>
        </div>
        
        <?php if ($errorMessage): ?>
        <div class="error-message">
            ⚠️ <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        <?php endif; ?>
        
        <div class="leaderboard">
            <table>
                <thead>
                    <tr>
                        <th class="center">Rank</th>
                        <th>Team</th>
                        <th class="center">Total Points</th>
                        <th class="center">GW Points</th>
                        <th class="center overall-rank-col">Overall Rank</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['standings'] as $standing): ?>
                    <tr>
                        <td class="center rank rank-<?php echo $standing['rank']; ?>">
                            <?php if ($standing['rank'] <= 3): ?>
                                <span class="rank-medal"><?php echo $standing['rank']; ?></span>
                            <?php else: ?>
                                <?php echo $standing['rank']; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="team-info">
                                <div class="team-name"><?php echo htmlspecialchars($standing['entry_name']); ?></div>
                                <div class="manager-name"><?php echo htmlspecialchars($standing['player_name']); ?></div>
                            </div>
                        </td>
                        <td class="center total-points"><?php echo number_format($standing['total_points']); ?></td>
                        <td class="center gw-points"><?php echo $standing['event_points']; ?></td>
                        <td class="center overall-rank overall-rank-col"><?php echo number_format($standing['overall_rank']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <button class="refresh-button" id="refreshBtn" onclick="handleRefresh()">
            ↻ Refresh
        </button>
    </div>
    
    <script>
        // Live timestamp updater
        const lastUpdatedTime = new Date('<?php echo $data['last_updated']; ?>').getTime();
        
        function updateTimestamp() {
            const now = Date.now();
            const diffSeconds = Math.floor((now - lastUpdatedTime) / 1000);
            
            let timeString;
            if (diffSeconds < 60) {
                timeString = diffSeconds + ' second' + (diffSeconds !== 1 ? 's' : '') + ' ago';
            } else if (diffSeconds < 3600) {
                const minutes = Math.floor(diffSeconds / 60);
                timeString = minutes + ' minute' + (minutes !== 1 ? 's' : '') + ' ago';
            } else {
                const hours = Math.floor(diffSeconds / 3600);
                timeString = hours + ' hour' + (hours !== 1 ? 's' : '') + ' ago';
            }
            
            document.querySelector('.last-updated').innerHTML = 
                'Last updated: <?php echo $data['last_updated']; ?> (' + timeString + ')';
        }
        
        // Update timestamp every second
        setInterval(updateTimestamp, 1000);
        updateTimestamp();
        
        // Refresh button rate limiting (30 seconds cooldown)
        const COOLDOWN_SECONDS = 30;
        const COOLDOWN_KEY = 'fpl_last_refresh_<?php echo $leagueId; ?>';
        
        function handleRefresh() {
            const lastRefresh = localStorage.getItem(COOLDOWN_KEY);
            const now = Date.now();
            
            if (lastRefresh) {
                const timeSinceRefresh = (now - parseInt(lastRefresh)) / 1000;
                if (timeSinceRefresh < COOLDOWN_SECONDS) {
                    const remaining = Math.ceil(COOLDOWN_SECONDS - timeSinceRefresh);
                    alert('Please wait ' + remaining + ' second' + (remaining !== 1 ? 's' : '') + ' before refreshing again.');
                    return;
                }
            }
            
            localStorage.setItem(COOLDOWN_KEY, now.toString());
            window.location.href = '?league=<?php echo $leagueId; ?>&refresh=1';
        }
        
        // Check cooldown on page load and update button state
        function checkCooldown() {
            const lastRefresh = localStorage.getItem(COOLDOWN_KEY);
            const now = Date.now();
            const btn = document.getElementById('refreshBtn');
            
            if (lastRefresh) {
                const timeSinceRefresh = (now - parseInt(lastRefresh)) / 1000;
                if (timeSinceRefresh < COOLDOWN_SECONDS) {
                    const remaining = Math.ceil(COOLDOWN_SECONDS - timeSinceRefresh);
                    btn.disabled = true;
                    btn.textContent = '⏳ Wait ' + remaining + 's';
                    
                    const interval = setInterval(() => {
                        const newTimeSinceRefresh = (Date.now() - parseInt(lastRefresh)) / 1000;
                        const newRemaining = Math.ceil(COOLDOWN_SECONDS - newTimeSinceRefresh);
                        
                        if (newRemaining <= 0) {
                            btn.disabled = false;
                            btn.textContent = '↻ Refresh';
                            clearInterval(interval);
                        } else {
                            btn.textContent = '⏳ Wait ' + newRemaining + 's';
                        }
                    }, 1000);
                }
            }
        }
        
        checkCooldown();
    </script>
</body>
</html>
    <?php
    exit;
}

/**
 * Display homepage with instructions
 */
function displayHomepage($error = null) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FPL Real-Time Leaderboard</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>⚽</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 800px;
            width: 100%;
        }
        
        .card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }
        
        h1 {
            color: #37003c;
            font-size: 2.5em;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1em;
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            font-weight: 600;
            color: #37003c;
            margin-bottom: 8px;
            font-size: 1.1em;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1.1em;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        select {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1.1em;
            background: white;
            cursor: pointer;
        }
        
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            width: 100%;
            background: #37003c;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 1.2em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        button:hover {
            background: #00ff87;
            color: #37003c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .instructions {
            background: #f8f9ff;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        
        .instructions h2 {
            color: #37003c;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        
        .instructions ol {
            margin-left: 20px;
            line-height: 1.8;
            color: #555;
        }
        
        .instructions li {
            margin-bottom: 10px;
        }
        
        .screenshot {
            background: #e9ecef;
            border: 2px dashed #adb5bd;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            color: #6c757d;
            margin: 20px 0;
            font-style: italic;
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 25px;
        }
        
        .feature {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 2px solid #f0f0f0;
        }
        
        .feature-icon {
            font-size: 2em;
            margin-bottom: 10px;
        }
        
        .feature-title {
            font-weight: 600;
            color: #37003c;
            margin-bottom: 5px;
        }
        
        .feature-desc {
            font-size: 0.9em;
            color: #666;
        }
        
        .example {
            background: #fff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 15px 0;
            font-family: 'Courier New', monospace;
            color: #37003c;
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 25px;
            }
            
            h1 {
                font-size: 2em;
            }
            
            .features {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>⚽ FPL Real-Time Leaderboard</h1>
            <p class="subtitle">View live Fantasy Premier League standings with accurate points</p>
            
            <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="get" action="">
                <div class="form-group">
                    <label for="league">League ID</label>
                    <input 
                        type="text" 
                        id="league" 
                        name="league" 
                        placeholder="e.g., 309812" 
                        required
                        pattern="[0-9]+"
                        title="Please enter a valid numeric league ID"
                    >
                </div>
                
                <div class="form-group">
                    <label for="format">Output Format</label>
                    <select id="format" name="format">
                        <option value="html">HTML (Interactive Leaderboard)</option>
                        <option value="json">JSON (API Response)</option>
                        <option value="csv">CSV (Download)</option>
                    </select>
                </div>
                
                <button type="submit">View Leaderboard →</button>
            </form>
        </div>
        
        <div class="card instructions">
            <h2>📋 How to Find Your League ID</h2>
            <ol>
                <li>Go to the <strong>Fantasy Premier League website</strong> and log in to your account</li>
                <li>Navigate to the <strong>Leagues & Cups</strong> section from the main menu</li>
                <li>Click on the <strong>league</strong> you want to view</li>
                <li>Look at the URL in your browser's address bar</li>
                <li>The league ID is the <strong>number</strong> in the URL after <code>/leagues/</code></li>
            </ol>
            
            <div class="example">
                Example: https://fantasy.premierleague.com/leagues/309812/standings/c
                <br>→ League ID is <strong>309812</strong>
            </div>
            
            <div class="screenshot">
                <img src="screenshots/step1.png" alt="FPL Leagues & Cups navigation" style="max-width: 100%; height: auto; border-radius: 8px;">
            </div>
            
            <div class="screenshot">
                <img src="screenshots/step2.png" alt="League standings page with URL bar highlighted showing the league ID" style="max-width: 100%; height: auto; border-radius: 8px;">
            </div>
        </div>
        
        <div class="card">
            <h2 style="color: #37003c; margin-bottom: 20px; text-align: center;">✨ Features</h2>
            <div class="features">
                <div class="feature">
                    <div class="feature-icon">🎯</div>
                    <div class="feature-title">Real-Time Data</div>
                    <div class="feature-desc">Live updates with 4-minute smart caching</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">📊</div>
                    <div class="feature-title">Accurate Points</div>
                    <div class="feature-desc">Fetches from individual entry APIs</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">🏆</div>
                    <div class="feature-title">Top 3 Medals</div>
                    <div class="feature-desc">Gold, silver, bronze rankings</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">📱</div>
                    <div class="feature-title">Responsive Design</div>
                    <div class="feature-desc">Works on all devices</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">🔄</div>
                    <div class="feature-title">Multiple Formats</div>
                    <div class="feature-desc">HTML, JSON, and CSV export</div>
                </div>
                <div class="feature">
                    <div class="feature-icon">⚡</div>
                    <div class="feature-title">Fast & Reliable</div>
                    <div class="feature-desc">Intelligent caching system</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// ===== MAIN EXECUTION =====

// Get parameters
$leagueId = isset($_GET['league']) ? trim($_GET['league']) : null;
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'html';
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';

// Validate format
if (!in_array($format, ['html', 'json', 'csv'])) {
    $format = 'html';
}

// If no league ID provided, show homepage
if (!$leagueId) {
    displayHomepage();
}

// Validate league ID is numeric
if (!is_numeric($leagueId)) {
    displayHomepage('Invalid league ID. Please enter a numeric league ID.');
}

// Server-side rate limiting for forced refresh
if ($forceRefresh) {
    $refreshCheck = isRefreshAllowed($leagueId);
    if (!$refreshCheck['allowed']) {
        if ($format === 'json') {
            header('Content-Type: application/json');
            http_response_code(429);
            echo json_encode([
                'error' => true,
                'message' => 'Rate limit exceeded. Please wait ' . $refreshCheck['remaining'] . ' seconds before refreshing again.',
                'retry_after' => $refreshCheck['remaining']
            ]);
            exit;
        } else {
            displayHomepage('Rate limit exceeded. Please wait ' . $refreshCheck['remaining'] . ' seconds before refreshing again.');
        }
    }
    recordRefresh($leagueId);
}

// Fetch league data
try {
    $data = fetchLeagueData($leagueId, $forceRefresh);
    $cacheAge = getCacheAge($leagueId);
    
    // Output based on format
    switch ($format) {
        case 'json':
            outputJSON($data);
            break;
        case 'csv':
            outputCSV($data);
            break;
        default:
            outputHTML($data, $cacheAge);
            break;
    }
    
} catch (Exception $e) {
    if ($format === 'json') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage()
        ]);
    } else {
        displayHomepage('Failed to fetch league data: ' . $e->getMessage());
    }
}
