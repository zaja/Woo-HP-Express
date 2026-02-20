# WooCommerce HP Express Shipping

Integracija s HP Express (Hrvatska Pošta) dostavom za WooCommerce.

## Značajke

- **Višestruke shipping metode** - Kreirajte različite metode dostave koristeći HP Express kao engine
- **Sve HP usluge** - Podrška za D+1, D+2, D+3, D+4, paleta D+5, EasyReturn
- **Tipovi dostave** - Adresa, pošta, paketomat
- **Paketomat picker** - Interaktivna karta za odabir paketomata na checkoutu (Leaflet + OpenStreetMap)
- **Ručno kreiranje pošiljki** - Admin odlučuje kada i s kojom uslugom poslati
- **Ispis naljepnica** - PDF (CODE39, CODE128) i ZPL format
- **Praćenje pošiljki** - Status i povijest skeniranja u admin i customer view
- **Tracking u emailovima** - Automatski tracking info u email notifikacijama kupcu
- **COD podrška** - Automatska otkupnina za narudžbe s pouzećem
- **Validacija mobilnog broja** - Provjera hrvatskog mobilnog za poštu i paketomat
- **WooCommerce Blocks** - Podrška za klasični i Blocks checkout
- **HPOS kompatibilnost** - Podržava WooCommerce High-Performance Order Storage

## Zahtjevi

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- HP Express ugovor i API pristupni podaci

## Instalacija

1. Uploadajte `woo-hp-express` folder u `/wp-content/plugins/`
2. Aktivirajte plugin kroz 'Plugins' izbornik u WordPressu
3. Idite na **WooCommerce → HP Express** i unesite API podatke
4. Konfigurirajte podatke pošiljatelja
5. Dodajte HP Express shipping metode u željene zone

## Konfiguracija

### 1. API Postavke

Idite na **WooCommerce → HP Express**:

- **Test način rada** - Uključite za testiranje s test API-jem
- **Korisničko ime** - Vaše HP API korisničko ime
- **Lozinka** - Vaša HP API lozinka
- **CECODE** - Vaš korisnički kod kod HP-a

Test podaci:
- Username: `testweb`
- Password: `testweb`
- CECODE: `111111`

### 2. Podaci pošiljatelja

Unesite podatke vaše tvrtke koji će se koristiti kao pošiljatelj:
- Naziv tvrtke
- Telefon
- Email
- Adresa (ulica, kućni broj, poštanski broj, grad)

### 3. Shipping Zone

1. Idite na **WooCommerce → Settings → Shipping**
2. Odaberite ili kreirajte zonu
3. Kliknite "Add shipping method"
4. Odaberite "HP Express"
5. Konfigurirajte postavke instance:
   - Naziv metode (npr. "HP Express D+2", "Dostava u paketomat")
   - Cijena dostave
   - Prag za besplatnu dostavu
   - Zadana usluga (D+1 do D+5)
   - Tip dostave (adresa/pošta/paketomat)
   - Veličina pretinca (za paketomat)

## Korištenje

### Kreiranje pošiljke

1. Otvorite narudžbu u WooCommerce adminu
2. U metaboxu "HP Express Pošiljka" odaberite:
   - Uslugu (D+1, D+2, itd.)
   - Tip dostave
   - Težinu paketa
   - COD opciju (ako je primjenjivo)
3. Kliknite "Kreiraj pošiljku"

### Ispis naljepnice

Nakon kreiranja pošiljke:
- Kliknite "PDF Naljepnica" za standardni format
- Kliknite "PDF (CODE128)" za alternativni barcode

### Praćenje statusa

- Kliknite ↻ gumb za osvježavanje statusa
- Prikazuje se povijest svih skeniranja

### Otkazivanje pošiljke

- Kliknite "Otkaži" za poništenje pošiljke
- Pošiljka mora biti u statusu NOV (nova)

## HP Usluge

| Kod | Naziv | Rok dostave |
|-----|-------|-------------|
| 26 | Paket 24 D+1 | Sljedeći radni dan |
| 29 | Paket 24 D+2 | 2 radna dana |
| 32 | Paket 24 D+3 | 3 radna dana |
| 38 | Paket 24 D+4 | 4 radna dana |
| 39 | EasyReturn D+3 (opcija 1) | Povrat |
| 40 | EasyReturn D+3 (opcija 2) | Povrat |
| 46 | Paletna pošiljka D+5 | 5 radnih dana |

## Tipovi dostave

| Kod | Naziv | Napomena | Mobilni obavezan |
|-----|-------|----------|------------------|
| 1 | Adresa | Standardna dostava na adresu | Ne |
| 2 | Pošta | Preuzimanje u pošti (HP određuje lokaciju) | Da |
| 3 | Paketomat | Kupac bira paketomat na checkoutu | Da |

### Validacija mobilnog broja

Za tipove dostave **Pošta** i **Paketomat** obavezan je hrvatski mobilni broj.

Podržani formati:
- `+385911234567`
- `00385911234567`
- `0911234567`
- `911234567`

Podržani prefiksi: **091, 092, 095, 097, 098, 099**

## Veličine pretinca (paketomat)

| Kod | Dimenzije |
|-----|-----------|
| X | XS (9×16×64 cm) |
| S | S (9×38×64 cm) |
| M | M (19×38×64 cm) |
| L | L (39×38×64 cm) |

## Paketomat Picker

Kad je shipping metoda konfigurirana za tip dostave "Paketomat", na checkoutu se prikazuje interaktivna karta:

- **Leaflet karta** s OpenStreetMap podlogom
- **Lista paketomata** s pretraživanjem
- **Vizualna potvrda** odabranog paketomata
- **Podrška za WooCommerce Blocks** checkout

Odabrani paketomat se prikazuje:
- Na checkout stranici (vizualna potvrda)
- U admin narudžbi (shipping sekcija)
- U customer accountu (order details)

## Kompatibilnost s Checkout tipovima

Plugin potpuno podržava **oba** WooCommerce checkout tipa:

### Classic Checkout (Shortcode)
- Standardni WooCommerce checkout (`[woocommerce_checkout]`)
- Paketomat picker koristi jQuery event listeners
- Podaci se spremaju u hidden form fields
- Radi s hookovima `woocommerce_checkout_create_order`

### Block-based Checkout (WooCommerce Blocks)
- Noviji checkout baziran na Gutenberg blokovima
- Paketomat picker koristi MutationObserver za praćenje DOM promjena
- Dinamički kreira picker UI kad se detektira paketomat shipping metoda
- **AJAX sprema odabir u WC Session** - ne ovisi o POST formama
- Radi s hookovima `woocommerce_checkout_order_created` i `woocommerce_store_api_checkout_update_order_from_request`

### Kako radi detekcija

Plugin automatski detektira koji checkout se koristi i prilagođava ponašanje:

| Checkout | Detekcija shipping metode | Spremanje paketomata |
|----------|---------------------------|----------------------|
| Classic | `input[name^="shipping_method"]` | Hidden fields + POST |
| Blocks | Label tekst + CSS klase | AJAX → WC Session |

Nije potrebna nikakva dodatna konfiguracija - plugin automatski radi s oba tipa.

## Tracking i Obavijesti

### Email notifikacije

Tracking informacije se automatski dodaju u customer emailove:
- Completed order email
- Customer invoice
- Customer note

Email sadrži:
- Broj pošiljke (barcode)
- Link za praćenje na HP tracking stranici

### Customer Account

Na stranici narudžbe u customer accountu prikazuje se:
- Broj pošiljke
- Carrier (HP Express)
- Link za praćenje

### Tracking URL

Format: `https://posiljka.posta.hr/tragom-posiljke/tracking/trackingdata?barcode={BARCODE}`

## Struktura datoteka

```
woo-hp-express/
├── woo-hp-express.php              # Glavna plugin datoteka
├── includes/
│   ├── class-hp-api.php            # API komunikacija
│   ├── class-hp-shipping-method.php # WC Shipping Method
│   ├── class-hp-order.php          # Order metabox, tracking, email hooks
│   ├── class-hp-settings.php       # Pomoćne funkcije za postavke
│   └── class-hp-paketomat-picker.php # Paketomat picker za checkout
├── assets/
│   ├── css/
│   │   ├── admin.css               # Admin stilovi
│   │   └── paketomat-picker.css    # Picker stilovi
│   └── js/
│       ├── admin.js                # Admin JavaScript
│       ├── settings.js             # Settings JavaScript
│       └── paketomat-picker.js     # Leaflet karta i picker logika
└── README.md
```

## API Limiti

- Maksimalna osigurana vrijednost: 13.300,00 EUR
- Maksimalni COD iznos: 13.300,00 EUR
- Maksimalna težina za paketomat: 30 kg
- Token traje 4 sata (automatski se osvježava)

## Troubleshooting

### "API credentials nisu konfigurirani"
Provjerite jeste li unijeli korisničko ime i lozinku u HP Express postavkama.

### "Podaci pošiljatelja nisu konfigurirani"
Unesite sve podatke pošiljatelja (naziv, telefon, adresa).

### "Za dostavu u paketomat potreban je ispravan hrvatski mobilni broj"
Broj telefona mora biti hrvatski mobilni (091, 092, 095, 097, 098, 099).
Podržani formati: +385911234567, 00385911234567, 0911234567

### "Za dostavu na poštu potreban je ispravan hrvatski mobilni broj"
Isto kao i za paketomat - potreban je mobilni broj za SMS obavijesti.

### Greška pri kreiranju pošiljke
- Provjerite jesu li svi podaci ispravni
- Provjerite HP API status na https://posiljka.posta.hr
- Uključite WP_DEBUG za detaljnije logove

## Changelog

### 1.1.0
- Paketomat picker s interaktivnom kartom (Leaflet + OpenStreetMap)
- Tracking info u customer emailovima
- Tracking info u customer account order view
- Validacija mobilnog broja za Poštu i Paketomat
- Podrška za WooCommerce Blocks checkout
- Prikaz odabranog paketomata u admin i customer view

### 1.0.0
- Inicijalna verzija
- Podrška za sve HP usluge
- Višestruke shipping method instance
- Kreiranje i otkazivanje pošiljki
- Ispis naljepnica (PDF/ZPL)
- Praćenje statusa
- COD podrška
- HPOS kompatibilnost

## Autor

**Normalno** - [https://svejedobro.hr](https://svejedobro.hr)

## Licenca

GPL v2 or later
