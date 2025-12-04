# ⚽ FPL Real-Time Leaderboard

A PHP-based web application that displays live Fantasy Premier League (FPL) league standings with real-time data fetched directly from the official FPL API. View accurate points, rankings, and gameweek performance in a beautiful, responsive interface.

![FPL Leaderboard](https://img.shields.io/badge/FPL-Live%20Leaderboard-37003C?style=for-the-badge&logo=premier-league)
![PHP](https://img.shields.io/badge/PHP-7.0%2B-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

## ✨ Features

- **Real-Time Data** - Fetches live standings from official FPL API
- **Accurate Points** - Retrieves precise total points from individual entry endpoints
- **Smart Caching** - 4-minute intelligent caching to reduce API load while keeping data fresh
- **Multiple Output Formats** - HTML (interactive), JSON (API), and CSV (export)
- **Beautiful Design** - Modern purple gradient UI with responsive layout
- **Top 3 Medals** - Gold, silver, and bronze styling for podium positions
- **Live Indicator** - Pulsing animation showing real-time data status
- **Mobile Responsive** - Optimized for all screen sizes
- **Gameweek Tracking** - Shows current gameweek and individual GW points
- **Overall Ranks** - Displays each team's global FPL ranking
- **One-Click Refresh** - Fixed refresh button for instant updates
- **User-Friendly Homepage** - Easy league ID input with helpful instructions

## 🚀 Quick Start

### Prerequisites

- PHP 7.0 or higher
- cURL extension enabled
- Web server (Apache, Nginx, or PHP built-in server)

### Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/Queazified/fpl-points-calculator.git
   cd fpl-points-calculator
   ```

2. **Ensure PHP and cURL are installed:**
   ```bash
   php -v
   php -m | grep curl
   ```

3. **Start the application:**

   **Option A: PHP Built-in Server (Development)**
   ```bash
   php -S localhost:8000
   ```
   Then open http://localhost:8000/fpl.php in your browser.

   **Option B: Apache/Nginx (Production)**
   - Place files in your web server's document root (e.g., `/var/www/html/`)
   - Access via http://yourdomain.com/fpl.php

4. **The cache directory will be created automatically** when you first fetch league data.

## 📖 Usage

### Finding Your League ID

1. Go to [Fantasy Premier League](https://fantasy.premierleague.com/) and log in
2. Navigate to **Leagues & Cups** from the menu
3. Select the league you want to view
4. Look at the URL in your browser:
   ```
   https://fantasy.premierleague.com/leagues/309812/standings/c
                                              ^^^^^^
                                           This is your League ID
   ```

<!-- Screenshot Placeholder: Add screenshot showing FPL website navigation -->

<!-- Screenshot Placeholder: Add screenshot showing league URL with ID highlighted -->

### Using the Application

#### 1. Interactive HTML Leaderboard (Default)
```
http://localhost:8000/fpl.php?league=309812
```
Displays a beautiful, responsive leaderboard with:
- League name and current gameweek
- Live indicator with pulse animation
- Sortable standings with ranks
- Total points, gameweek points, and overall ranks
- Last updated timestamp
- One-click refresh button

#### 2. JSON API Response
```
http://localhost:8000/fpl.php?league=309812&format=json
```
Returns structured JSON data:
```json
{
  "league": {
    "id": 309812,
    "name": "Example League",
    "current_gameweek": 15
  },
  "standings": [
    {
      "rank": 1,
      "entry_id": 12345,
      "entry_name": "My Team",
      "player_name": "John Doe",
      "total_points": 1234,
      "event_points": 67,
      "overall_rank": 123456
    }
  ],
  "last_updated": "2025-12-04 15:30:45"
}
```

#### 3. CSV Export
```
http://localhost:8000/fpl.php?league=309812&format=csv
```
Downloads a CSV file with all standings data, perfect for:
- Excel/Google Sheets analysis
- Data archiving
- Custom reporting

### Advanced Options

#### Force Refresh (Bypass Cache)
```
http://localhost:8000/fpl.php?league=309812&refresh=1
```
Forces a fresh API call, bypassing the cache.

#### URL Parameters

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `league` | Yes | - | Numeric league ID from FPL website |
| `format` | No | `html` | Output format: `html`, `json`, or `csv` |
| `refresh` | No | `0` | Set to `1` to force cache bypass |

## 🔧 Configuration

Edit these constants in `fpl.php` to customize behavior:

```php
define('CACHE_DIR', __DIR__ . '/cache');    // Cache directory location
define('CACHE_DURATION', 240);               // Cache duration (4 minutes)
define('API_TIMEOUT', 15);                   // API request timeout (seconds)
```

### Adjusting Cache Duration

The default 4-minute cache provides a good balance between fresh data and API load. Adjust based on your needs:

- **More frequent updates** (e.g., during live gameweeks): `180` (3 minutes)
- **Less API load** (e.g., off-season): `600` (10 minutes)
- **Testing/Development**: `30` (30 seconds)

## 🌐 API Endpoints Used

This application fetches data from the official Fantasy Premier League API:

1. **Bootstrap Static** - Current gameweek information
   ```
   https://fantasy.premierleague.com/api/bootstrap-static/
   ```

2. **League Standings** - League data and entry list
   ```
   https://fantasy.premierleague.com/api/leagues-classic/{league_id}/standings/
   ```

3. **Individual Entry** - Accurate total points for each team
   ```
   https://fantasy.premierleague.com/api/entry/{entry_id}/
   ```

## 🎨 Design Specifications

### Color Scheme
- **Background Gradient**: `#667eea` → `#764ba2` (Purple)
- **FPL Brand**: `#37003c` (Dark Purple)
- **Accent/Live**: `#00ff87` (Bright Green)
- **Cards**: White with rounded corners and shadows

### Top 3 Styling
- **1st Place**: Gold medal (`#FFD700`)
- **2nd Place**: Silver medal (`#C0C0C0`)
- **3rd Place**: Bronze medal (`#CD7F32`)

### Responsive Breakpoints
- Desktop: Full table with all columns
- Mobile (<768px): Hides manager names and overall rank columns

## 🛠️ Troubleshooting

### "Failed to fetch league data" Error

**Possible causes:**
- Invalid league ID
- FPL API is down or rate-limiting
- Network connectivity issues
- cURL not enabled

**Solutions:**
1. Verify the league ID is correct and numeric
2. Check if https://fantasy.premierleague.com/ is accessible
3. Ensure cURL is enabled: `php -m | grep curl`
4. Try using `?refresh=1` to bypass cache
5. Check PHP error logs for detailed information

### Cache Issues

**To clear the cache:**
```bash
rm -rf cache/*
```

**To disable caching temporarily:**
- Always use `?refresh=1` parameter
- Or set `CACHE_DURATION` to `0` in the code

### Page Shows Blank/White Screen

**Check:**
1. PHP version: `php -v` (must be 7.0+)
2. Error reporting: Add to top of `fpl.php`:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
3. File permissions: Cache directory must be writable
   ```bash
   chmod 755 cache/
   ```

### Mobile Layout Issues

- Clear browser cache
- Test in incognito/private mode
- Verify viewport meta tag is present

## 📊 Performance

- **First Load**: ~2-5 seconds (depends on league size and API response time)
- **Cached Load**: <100ms (instant)
- **Memory Usage**: ~5-10MB (typical league with 20 teams)
- **Cache Storage**: ~10-50KB per league

## 🤝 Contributing

Contributions are welcome! Feel free to:
- Report bugs
- Suggest new features
- Submit pull requests
- Improve documentation

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Fantasy Premier League for providing the public API
- The FPL community for inspiration
- All contributors and users

## 📞 Support

If you encounter any issues or have questions:
1. Check the [Troubleshooting](#-troubleshooting) section
2. Review [existing issues](https://github.com/Queazified/fpl-points-calculator/issues)
3. Open a new issue with detailed information

## 🔮 Future Enhancements

Potential features for future releases:
- Historical data tracking and charts
- League comparison tool
- Email/push notifications for rank changes
- Head-to-head league support
- Mini-league predictions
- Player statistics integration
- Dark mode toggle

---

**Made with ⚽ for FPL managers everywhere**