[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *ModuleTemplate* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/ModuleTemplate.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

alle Funktionen sind über _RequestAction_ der jew. Variablen ansteuerbar

## 5. Konfiguration

### ModuleTemplate Device

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Instanz deaktivieren      | boolean  | false        | Instanz temporär deaktivieren |
|                           |          |              | |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
* Integer<br>
* Float<br>
* String<br>

## 6. Anhang

### GUIDs
- Modul: `{0DCE4B6F-9176-DC9A-5502-1AF2B7573EB5}`
- Instanzen:
  - ModuleTemplateDevice: `{B052AEAB-2687-02EB-DF40-74191E242A0B}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 0.9 @ dd.mm.yyyy HH:MM (beta)
  - Initiale Version
