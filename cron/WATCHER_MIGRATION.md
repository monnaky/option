# File Signal Watcher Migration Guide

## Problem Solved

The original `file_signal_watcher.sh` was crashing repeatedly with termination errors. This has been replaced with a stable PHP-based polling solution.

## New Solution: `file_signal_watcher.php`

### Key Improvements

1. **No External Dependencies**: No longer requires `inotify-tools` (inotifywait)
2. **Docker-Friendly**: Uses simple file polling that works reliably in containers
3. **Crash-Resistant**: Proper error handling and automatic recovery
4. **Signal Handling**: Graceful shutdown on SIGTERM/SIGINT
5. **Error Backoff**: Automatically backs off after too many errors

### How It Works

- Polls `getSignal.txt` every 1 second (configurable)
- Detects changes by comparing file modification time and size
- Only processes when content actually changes
- Automatically recovers from errors
- Logs all activity to `logs/file_signal_watcher.log`

## Migration Steps

### Option 1: Replace in Systemd Service

If you have a systemd service, update it:

```ini
[Unit]
Description=VTM Option File Signal Watcher (PHP)
After=network.target

[Service]
Type=simple
WorkingDirectory=/app
ExecStart=/usr/bin/php /app/cron/file_signal_watcher.php
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

### Option 2: Docker/Coolify Deployment

Add to your Dockerfile or startup script:

```bash
# Run the PHP watcher in the background
php /var/www/html/cron/file_signal_watcher.php &
```

Or use a process manager like supervisord:

```ini
[program:file_signal_watcher]
command=php /var/www/html/cron/file_signal_watcher.php
autostart=true
autorestart=true
stderr_logfile=/var/log/file_signal_watcher.err.log
stdout_logfile=/var/log/file_signal_watcher.out.log
```

### Option 3: Manual Start

```bash
# Start the watcher
php cron/file_signal_watcher.php

# Or run in background
nohup php cron/file_signal_watcher.php > /dev/null 2>&1 &
```

## Stopping the Old Watcher

1. Find the process:
   ```bash
   ps aux | grep file_signal_watcher.sh
   ```

2. Stop it gracefully:
   ```bash
   kill <PID>
   ```

3. Or if running as a service:
   ```bash
   systemctl stop file-signal-watcher
   ```

## Testing

1. Start the new watcher:
   ```bash
   php cron/file_signal_watcher.php
   ```

2. In another terminal, write a test signal:
   ```bash
   echo "XRPUSD,Buy Message from MT5,1764039334" > getSignal.txt
   ```

3. Check the logs:
   ```bash
   tail -f logs/file_signal_watcher.log
   ```

## Configuration

You can adjust these variables in `file_signal_watcher.php`:

- `$POLL_INTERVAL`: How often to check the file (default: 1 second)
- `$MAX_ERRORS`: Max consecutive errors before backoff (default: 10)
- `$ERROR_BACKOFF`: Seconds to wait after max errors (default: 5)

## Troubleshooting

### Watcher not detecting changes
- Check file permissions: `chmod 644 getSignal.txt`
- Check log file: `tail -f logs/file_signal_watcher.log`
- Verify processor script exists: `ls -la cron/file_signal_processor.php`

### High CPU usage
- Increase `$POLL_INTERVAL` to 2-3 seconds
- Check if processor script is hanging

### Watcher keeps restarting
- Check logs for error patterns
- Verify PHP CLI is available: `php -v`
- Check disk space: `df -h`

## Rollback

If you need to rollback to the bash script:

1. Stop the PHP watcher
2. Start the bash script: `bash cron/file_signal_watcher.sh`
3. Ensure `inotify-tools` is installed: `apt-get install inotify-tools`

