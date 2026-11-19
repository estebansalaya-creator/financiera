@echo off
REM ============================================================================
REM subir_github.bat - commit y push de financiera a GitHub
REM
REM La primera vez arma el repositorio: pide la direccion del repositorio
REM (crearlo antes en github.com, PRIVADO y vacio, sin README) y sube todo.
REM Despues sube lo modificado y lo nuevo de esta carpeta (lo que no esta en
REM .gitignore: config.php, documentos, logs y las cargas de datos nunca suben).
REM El mensaje sale de mensaje_commit.txt si existe (y despues se borra);
REM si no, lo pide en una linea. Antes de commitear muestra todo y pregunta.
REM ============================================================================
setlocal
chcp 65001 >nul
REM El repositorio de GitHub (solo se usa la primera vez).
set "REPO_GITHUB=https://github.com/estebansalaya-creator/financiera.git"
cd /d "%~dp0"

where git >nul 2>nul
if errorlevel 1 ( echo No esta instalado git: bajarlo de https://git-scm.com/download/win & goto :error )

if exist ".git" goto :subir

echo === Primera vez: se arma el repositorio de esta carpeta.
echo     Crealo antes en GitHub: privado, vacio ^(sin README ni .gitignore^).
echo.
set "REMOTO=%REPO_GITHUB%"
echo Repositorio: %REPO_GITHUB%
set /p "REMOTO=Enter para usar ese, o escribi otra direccion: "
if not defined REMOTO ( echo Sin direccion no se puede seguir. & goto :error )
echo Se usa: %REMOTO%
git init -q -b main
if errorlevel 1 goto :error
git remote add origin "%REMOTO%"
if errorlevel 1 goto :error
git config user.name >nul 2>nul || (
  set /p "GNOMBRE=Tu nombre para los commits: "
  call git config user.name "%%GNOMBRE%%"
)
git config user.email >nul 2>nul || (
  set /p "GMAIL=Tu correo de GitHub: "
  call git config user.email "%%GMAIL%%"
)
set "PRIMERA=1"

:subir
if exist "app\config.php" (
  git check-ignore -q "app\config.php"
  if errorlevel 1 ( echo *** app\config.php no esta en .gitignore: NO se sube nada. & goto :error )
)

git add -A
if errorlevel 1 goto :error
git diff --cached --quiet
if not errorlevel 1 (
  echo No hay nada para subir: esta todo commiteado.
  git push -q 2>nul
  goto :fin
)

echo.
echo === Lo que va a subir:
git status --short
echo.

if exist "mensaje_commit.txt" (
  echo === Mensaje ^(mensaje_commit.txt^):
  type mensaje_commit.txt | more
) else if defined PRIMERA (
  set "MSG=Backoffice financiera: primera version"
  echo === Mensaje: Backoffice financiera: primera version
) else (
  set /p "MSG=Mensaje del commit: "
)
echo.
choice /c SN /m "Hacer el commit y subirlo a GitHub"
if errorlevel 2 (
  git reset -q
  echo Cancelado: no se subio nada y los archivos quedaron como estaban.
  goto :fin
)

if exist "mensaje_commit.txt" (
  git commit -q -F mensaje_commit.txt
) else (
  if not defined MSG set "MSG=Cambios en financiera"
  call git commit -q -m "%%MSG%%"
)
if errorlevel 1 goto :error

if defined PRIMERA (
  git push -u origin main
  if errorlevel 1 goto :error
  goto :listo
)

git pull --rebase --autostash
if errorlevel 1 (
  echo *** El commit quedo hecho en esta maquina pero no se pudo combinar con GitHub.
  echo     Resolver ^(git status^), git rebase --continue y volver a correr esto.
  goto :error
)
git push
if errorlevel 1 goto :error

:listo
if exist "mensaje_commit.txt" del /q mensaje_commit.txt
echo.
echo Listo: subido a GitHub.
git log -1 --format="%%h  %%s"
goto :fin

:error
echo.
echo *** Algo fallo: revisar el mensaje de arriba.

:fin
echo.
pause
endlocal
