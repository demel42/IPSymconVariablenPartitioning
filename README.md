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

Mit Hilfe des Moduls können Änderungen eine Quellvariable auf beliebig viele Zielvariablen aufgeteilt werden.
Dies macht besonders Sinn bei geloggten Variablen, ist aber nicht darauf eingeschränkt.

Hintergrund: wenn man man z.B. einen Zwischenstecker hat mit Verbrauchsmessung können die ermittelten Werte auf
unterschiedliche Geräte aufgeteilt werden; sei es, das man den gleichen Stecker für mehrere Verbrauch nutzt
(natürlich nicht zum gleichen Zeitpunkt) oder man nacheinander verschiedene Verbraucher damit überwacht und diese
Messungen trennen möchte oder indem man am Verbraucher etwas ändert.

Hierzu wird die Änderung der Quellvariable auf das jeweils aktuelle Ziel übernommen - für jedes konfigurierte Ziel wird vom Modul
eine eigene Statusvariable angelegt, die 1:1 so konfigurert ist, wie die Quellvariable.
Zwischen den Zielen kann mittels einer Variablen ausgewählt werden.

Weiterhin ist es optional möglich, Zwischensummen zu bilden, das d.h. es gibt zu dem Modul eine zweite Variablen, in der die
aufgelaufenen Änderungen aufsummiert werden.
Damit kann man z.B. zeitabhängig (z.B. stündlich oder täglich) die Änderungen speichern (durch ein zyklisches Ereignis) oder die Summe
eines "Durchlaufs", z.B. mit Hilfe des Moduls [Fertig-Melder](https://github.com/symcon/FertigMelder).

Zur Unterstützung der Aufarbeitung in der Vergangenheit protokollierter Werte kann man die Quellvariable auch nachträglich auf Ziele verteilen (Instanz-Dialog unter _Experten-Bereich_.

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *VariablePartioning* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/VariablePartioning.git` installiert werden.

### b. Einrichtung in IPS

In IP-Symcon nun unterhalb des Wurzelverzeichnisses die Funktion _Instanz hinzufügen_ auswählen, als Gerät _VariablenPartitioning_ auswählen.
Die Angabe der Quellvariable und min. einem Ziel im Instanz-Dialog ist zwingend erforderlich.

## 4. Funktionsreferenz

alle Funktionen sind über _RequestAction_ der jeweiligen Variablen ansteuerbar sowie über Aktionen

`void VariablenPartioning_SubtotalBuild(integer $InstanzID)`<br>
Summiert die Änderungen der Zielvariablen in eine eigene geloggte Variable.

`void VariablenPartioning_SubtotalInitialize(integer $InstanzID)`<br>
Standardmässig wird bei jedem _SubtotalBuild_ auf den Wert des vorigen _SubtotalBuild_ Bezug genommen. Mit dieser Funktion kann aber der aktuelle Wert als neuer Bezugswert gesetzt werden.

## 5. Konfiguration

### VariablePartioning

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Instanz deaktivieren      | boolean  | false        | Instanz temporär deaktivieren |
|                           |          |              | |
| Quellvariable             | integer  | 0            | (geloggte) Variable mit Messungen |
| Ziele                     | table    |              | Angabe möglicher Ziele |

* Ziele<br>
  Die Tabelle enthält folgende Eigenschaften:
  * **Ident**<br>
    wird als Ident der Zielvariable(n) verwendet mit dem Vorsatz *VAR_* (Zähler) bzw. *SUB_* (Zwischensumme).<br>
    Wichtig: der Ident kann so nicht geändert werden, damit würden die Variablen gelöscht werfden; wenn erforderlich siehe Hilfaktion im _Experten-Bereich_
  * **Name**<br>
    Bezeichnung der Variablen, wird beim Speichern der Konfiguration immer wieder neu gesetzt
  * **Zwischensumme**<br>
    Ermöglicht die Nuةzung der Zwischensummen-Funktionalität für dieses Ziel
  * **inaktiv**<br>
    Inaktive Ziele werden in der Auswahl-Variable nicht mehr angeboten

Wichtig: die Einstellungen der Quellvariable (┃ariablentyp, Variablenprofile, Archiv-Einstellungen) werden von der Quellvariable in die Zielvariable(n) übernommen

#### Aktionen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| Zwischensumme bilden         | s.o. |
| Zwischensumme initialisieren | s.o. |
|                              | |
| (Neu-)Aufteilung...          | (Neu-)Aufteilung der Archivdaten aus der Quellvariable, dabei wird das Ziel gelöscht und neu aufgebaut |
| Ident eines Ziels ändern     | Ändern des Idents eines Ziels unter Erhalt der Zielvariablen |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* String<br>
VariablenPartioning_\<Instance-ID\>.Destinations

## 6. Anhang

### GUIDs
- Modul: `{21D9AA11-FD7D-E695-E2CF-8338D250BBEA}`
- Instanzen:
  - VariablePartioning: `{E689DE3B-64B1-98D9-C7C1-AE0E96B25138}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 1.0 @ 10.09.2022 10:16
  - Initiale Version
