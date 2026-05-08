# KiviTickets

Bussi- või rongipileti müügi- ja valideerimissüsteem. Võimaldab administraatoritel hallata sõite ja töötajatel müüa ning valideerida pileteid.

## Funktsioonid

- Kasutajate registreerimine ja e-posti kinnitus
- Administraatori paneel sõitude ja kasutajate haldamiseks
- Pileti müük ja QR-koodiga valideerimine
- Töötaja profiil ja tööreziim
- Sõidu aktiveerimine ja lõpetamine

## Paigaldamine

### 1. Klooni repositoorium

```bash
git clone https://github.com/EestiLatern/KiviTickets
cd kivitickets
```

### 2. Loo andmebaas

Loo MySQL andmebaas ja impordi struktuur:

```bash
mysql -u root -p -e "CREATE DATABASE kivitickets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p kivitickets < schema.sql
```

### 3. Seadista keskkond

Kopeeri näidisfail ja täida oma andmed:

```bash
cp .env.example .env
```

Ava `.env` ja täida:

```
DB_HOST=localhost
DB_USER=sinu_kasutajanimi
DB_PASSWORD=parool
DB_NAME=kivitickets
```

### 4. Veebiserverisse

Kopeeri failid oma veebiserverisse (Apache/Nginx) ja veendu, et PHP 8.x on saadaval.

## Turvamärkused

- `.env` fail **ei tohi** kunagi GitHubi jõuda — see on `.gitignore`-is juba blokeeritud
- Andmebaasi dump (`*.sql`) on samuti blokeeritud — kasuta ainult `schema.sql`-i (ilma pärisandmeteta)

## Nõuded

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache/Nginx
