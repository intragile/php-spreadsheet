# PHP Spreadsheet

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

**XLSX, ODS és CSV fájlok olvasása és írása bármekkora méretben, állandó memóriával és Composer-függőségek
nélkül.** A sorok egyenként áramlanak be és ki, így egy milliós sorszámú export ugyanannyi RAM-ot
használ, mint egy százsoros.

[English README → README.md](README.md)

```php
use IntrAgile\Spreadsheet\Spreadsheet;

$reader = Spreadsheet::read('/path/to/upload.xlsx');   // a formátumot a tartalomból ismeri fel

foreach ($reader->rows() as $number => $row) {
    // $row: int|float|string|bool|DateTimeImmutable|null értékek listája
}

$reader->close();
```

## Telepítés

```bash
composer require intragile/php-spreadsheet
```

PHP 8.3+ szükséges az `ext-zip`, `ext-xmlreader` és `ext-mbstring` bővítményekkel. Composer-függőség
nincs.

## Olvasás

```php
$reader = Spreadsheet::read('/path/to/file.ods');

$reader->sheets();                       // list<SheetInfo>: név, index, láthatóság
$reader->rows();                         // az első munkalap; rows($nevVagyIndex) másikat választ

foreach ($reader->rowsWithHeader() as $number => $record) {
    echo $record['email'];               // a levágott fejlécsor szerint kulcsolva
}

$reader->close();
```

- A `rows()` kulcsai a valódi, 1-től induló sorszámok; a teljesen üres sorokat kihagyja.
- A cellatípusok megmaradnak: szám, logikai érték, szöveg és dátum (`DateTimeImmutable`, az egyéni
  számformátumok mögötti dátumokat és az 1904-es dátumrendszert is beleértve). A CSV értékei mindig
  szövegek.
- Az ismétlődő vagy üres fejlécek `name_2` / `column_N` nevet kapnak.
- A CSV elválasztóját és kódolását a könyvtár felismeri (a magyar Excel Windows-1250 kódolású,
  pontosvesszős exportját is), és felül is lehet írni:

```php
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\ReadOptions;

$reader = Spreadsheet::read(
    $path,
    new ReadOptions(csvDelimiter: ';', csvEncoding: 'Windows-1250'),
    Format::Csv,
);
```

Hiányzó vagy olvashatatlan fájlnál `SpreadsheetException`, `.xls`, Numbers és PDF fájlnál
`UnsupportedFormat` kivétel keletkezik.

## Írás

A formátum a fájlkiterjesztésből adódik (`.xlsx`, `.ods`, `.csv`), vagy megadható `Format`.

```php
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Style;

$writer = Spreadsheet::write('/path/to/report.xlsx');

$header = Style::make()->bold()->fill('DDDDDD')->border();

$writer
    ->sheet('Rendelések', new SheetOptions(columnWidths: [1 => 12, 2 => 30], freezeRows: 1, autoFilter: true))
    ->addRow(['Azonosító', 'Vevő', 'Összeg', 'Rendelés napja'], $header);

foreach ($orders as $order) {                       // bármilyen iterable, generátor is
    $writer->addRow([
        $order->id,                                 // szám
        $order->customer,                           // mindig szövegként tárolódik
        Cell::of($order->total, Style::make()->numberFormat('#,##0.00')),
        $order->orderedAt,                          // DateTimeInterface -> dátum
    ]);
}

$writer->addRow(['', 'Összesen', Cell::formula('=SUM(C2:C100)')]);
$writer->close();                                   // a fájl záráskor, atomikusan jelenik meg
```

- A szövegeket a könyvtár soha nem alakítja át: a `"00123"`, `"1e5"` és `"=1+1"` szöveg marad.
  Képlethez a `Cell::formula()`, kényszerített szöveghez a `Cell::text()` való.
- Az XML-ben tiltott karaktereket eltávolítja, ahelyett hogy sérült fájlt írna.
- A munkalapnevek ellenőrzése (hossz, tiltott karakterek, kis-nagybetűtől független duplikátum)
  még az írás megkezdése előtt megtörténik.
- A `Style` megváltoztathatatlan; az azonos stílusok egyetlen bejegyzésen osztoznak a fájlban.

## Memória

Egyetlen olvasó vagy író sem tart a memóriában az aktuális soron (és korlátos gyorsítótárakon) kívül
mást. Az XLSX megosztott szövegei a `ReadOptions::$sharedStringsInMemory` darabszámig a RAM-ban
maradnak, afölött ideiglenes fájlba kerülnek. Az írók ideiglenes fájlba streamelnek, és a `close()`
a helyére nevezi át. A tesztek ellenőrzik, hogy a memória-csúcs nem nő a sorok számával.

## Biztonsági korlátok

A feltöltött fájlok megbízhatatlanok, ezért az olvasók korlátokat érvényesítenek (mind állítható a
`ReadOptions`-ben):

| Beállítás | Alapérték | Mi ellen véd |
|---|---|---|
| `maxZipEntries` | 5 000 | rengeteg bejegyzést tartalmazó archívumok |
| `maxUncompressedBytes` | 4 GiB | zip-bombák, a ténylegesen kicsomagolt bájtokon mérve |
| `maxCompressionRatio` | 200 | az 1 MiB-nál nagyobb, 200:1 fölé táguló bejegyzések |

Az 1 048 576. soron (az Excel és a LibreOffice maximuma) túli sor `LimitExceeded` kivételt ad, az
XML DOCTYPE-deklarációkat elutasítja (nincs XXE), a nem biztonságos zip-bejegyzésneveket pedig
visszautasítja. A korlátok megsértése `LimitExceeded`, a sérült fájl `CorruptFile` kivételt ad;
mindkettő a `SpreadsheetException` leszármazottja.

## Teljesítménymérés

Lásd: [bench/RESULTS.md](bench/RESULTS.md).

## Fejlesztés

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
