@echo off
setlocal

if "%DB_HOST%"=="" set DB_HOST=127.0.0.1
if "%DB_PORT%"=="" set DB_PORT=3306
if "%DB_NAME%"=="" set DB_NAME=caterflow
if "%DB_USER%"=="" set DB_USER=root
if "%BACKUP_DIR%"=="" set BACKUP_DIR=%~dp0..\database\backups

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

for /f "tokens=1-4 delims=/ " %%a in ('date /t') do set dt=%%d%%b%%c
for /f "tokens=1-2 delims=:" %%a in ('time /t') do set tm=%%a%%b
set tm=%tm: =0%
set OUT_FILE=%BACKUP_DIR%\caterflow_%dt%_%tm%.sql

if "%DB_PASS%"=="" (
  mysqldump -h %DB_HOST% -P %DB_PORT% -u %DB_USER% %DB_NAME% > "%OUT_FILE%"
) else (
  mysqldump -h %DB_HOST% -P %DB_PORT% -u %DB_USER% -p%DB_PASS% %DB_NAME% > "%OUT_FILE%"
)

if errorlevel 1 (
  echo Backup failed.
  exit /b 1
)

echo Backup created: %OUT_FILE%
endlocal
