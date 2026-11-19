@echo off
rem Abre PowerShell como administrador (Windows pide confirmacion) y crea el sitio en IIS.
powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -NoExit -File %~dp0instalar_iis.ps1'"
