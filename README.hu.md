# PHP Spreadsheet

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

**XLSX, ODS és CSV fájlok olvasása és írása Composer-függőségek nélkül.** A sorok egyenként
áramlanak be és ki, így a memóriahasználat a sorok számával nem nő (lásd: [Memória](#memória) és
[bench/RESULTS.md](bench/RESULTS.md)).

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

PHP 8.3+ szükséges az `ext-zip`, `ext-xmlreader`, `ext-mbstring` és `ext-iconv` bővítményekkel.
Composer-függőség nincs.

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
- Az ismétlődő vagy üres fejlécek `name_2` / `column_N` nevet kapnak. Az egész számot tartalmazó
  fejléc (például `2024`) `int` tömbkulcs lesz, mert a PHP az ilyen kulcsokat mindig így tárolja: a
  `$record[2024]` és a `$record['2024']` is működik, de az `array_keys()` `int` típusú `2024`-et ad.
- A dátumformátumú XLSX-cella, amelynek sorszáma kívül esik a táblázatkezelők naptárán (0 alatti
  vagy 9999-12-31 utáni), dátum helyett az eredeti számként jön vissza.
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

- A más kódolású CSV-t veszteségmentesen alakítja UTF-8-ra: UTF-16 és UTF-32 (BOM-mal vagy anélkül),
  valamint egybájtos kódlapok, például Windows-1250 vagy ISO-8859-2. A kódlapban nem definiált bájt
  (például a `0x81` a Windows-1250-ben) `U+FFFD` karakter lesz, és nem hiúsítja meg az olvasást; más
  többbájtos kódolás `InvalidArgumentException` kivételt ad.

Hiányzó vagy olvashatatlan fájlnál `SpreadsheetException`, `.xls`, Numbers és PDF fájlnál
`UnsupportedFormat` kivétel keletkezik.

## Írás

A formátum a fájlkiterjesztésből adódik (`.xlsx`, `.ods`, `.csv`/`.txt`; a `.txt` CSV-ként íródik), vagy megadható `Format`.
Az `.xlsm` olvasható, de nem írható, mert a megírt munkafüzetben nem lennének makrók.

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

- A szövegeket a könyvtár soha nem alakítja át: a `"00123"` és az `"1e5"` minden formátumban, az
  `"=1+1"` XLSX-ben és ODS-ben szöveg marad. Képlethez a `Cell::formula()`, kényszerített szöveghez a
  `Cell::text()` való. A CSV-ben nincsenek cellatípusok, ezért a CSV-t megnyitó táblázatkezelő az
  `=1+1`-et képletként futtatja; a képletek semlegesítéséről lásd: [CSV Excelhez](#csv-excelhez).
- Az ODS-képleteket OpenFormula alakra alakítja az egyszerű A1-hivatkozások, tartományok és a `,`
  argumentumelválasztó esetén. A más munkalapra mutató hivatkozások (`Sheet2!A1`), az idézőjeles nevek
  és a strukturált hivatkozások átalakítás nélkül íródnak ki, így az ilyen képlet a LibreOffice-ban
  nem feltétlenül számolható; ezekhez XLSX-et érdemes használni.
- Az XML-ben tiltott karaktereket eltávolítja, ahelyett hogy sérült fájlt írna.
- A munkalapnevek ellenőrzése (hossz, tiltott karakterek, kis-nagybetűtől független duplikátum)
  még az írás megkezdése előtt megtörténik.
- A `Style` megváltoztathatatlan; az azonos stílusok egyetlen bejegyzésen osztoznak a fájlban.

### CSV Excelhez

A `Spreadsheet::write('x.csv')` UTF-8 kódolású, `,`-vel tagolt fájlt ír. A magyar (és a legtöbb
kontinentális) Excel `;` elválasztót vár, és az UTF-8-at csak bájtsorrend-jelzővel (BOM) ismeri fel,
ezért hozzá közvetlenül kell létrehozni az írót:

```php
use IntrAgile\Spreadsheet\Csv\CsvWriter;

$writer = new CsvWriter('/path/to/export.csv', delimiter: ';', bom: true, escapeFormulas: true);
$writer->addRow(['Név', 'Összeg'])->addRow(['Árvíztűrő tükörfúrógép', 1234.5])->close();
```

A számok a területi beállítástól függetlenül `.` tizedesjellel íródnak. A magyar Excel az ilyen mezőt
szövegnek mutatja; ha számként kell látnia, tizedesvesszős szöveget adjunk át
(`number_format($total, 2, ',', '')`). A szövegként átadott negatív számokat az `escapeFormulas` is
semlegesíti, ezért tisztán számadatos exportnál hagyjuk kikapcsolva.

**CSV-injekció.** Az `=`, `+`, `-` vagy `@` jellel kezdődő mezőt az Excel és a LibreOffice megnyitáskor
képletként futtatja, így a felhasználótól származó szöveg, például
`=HYPERLINK("https://evil.example", "kattints")`, működésbe léphet az olvasó táblázatában. Az
`escapeFormulas: true` beállítással minden ilyen karakterrel, tabulátorral vagy kocsivissza
karakterrel kezdődő szöveges érték elé `'` kerül, amelyet a táblázatkezelők elrejtenek, és „szövegként
megjelenítendő” jelzésnek tekintenek. Alapértelmezésben ki van kapcsolva, mert megváltoztatja az
adatot (a `-5` szövegből `'-5` lesz); felhasználói adatot tartalmazó exportnál kapcsoljuk be. A
`Cell::formula()`-val átadott értékeket nem módosítja.

## Memória

Egyetlen olvasó vagy író sem tart a memóriában az aktuális soron (és rögzített méretű
gyorsítótárakon) kívül mást. Az XLSX megosztott szövegei a `ReadOptions::$sharedStringsInMemory`
darabszámig a RAM-ban maradnak; a többi ideiglenes fájlba kerül, az eltolásaikat tároló, rögzített
szélességű indexszel együtt, így a memória akárhány kiírt szövegnél is állandó (csak 64 KiB-os
előolvasási blokkokat tart). Az írók ideiglenes fájlba streamelnek, és a `close()`
a helyére nevezi át. A tesztek ellenőrzik, hogy a memória-csúcs nem nő a sorok számával.

## Biztonsági korlátok

A feltöltött fájlok megbízhatatlanok, ezért az olvasók korlátokat érvényesítenek (mind állítható a
`ReadOptions`-ben):

| Beállítás | Alapérték | Mi ellen véd |
|---|---|---|
| `maxZipEntries` | 5 000 | rengeteg bejegyzést tartalmazó archívumok |
| `maxUncompressedBytes` | 4 GiB | zip-bombák, minden bejegyzés a ténylegesen kicsomagolt bájtokon mérve a deklarált méretére (legalább 1 MiB) is korlátozva |
| `maxCompressionRatio` | 200 | az 1 MiB-nál nagyobb, 200:1 fölé táguló bejegyzések |
| `maxCellBytes` | 1 MiB | korlátlanul növő cella vagy CSV-mező: ODS szóközsorozatok (`text:s`), XLSX megosztott és beágyazott szövegek, elszabadult idézőjel utáni CSV-mező |
| `maxCells` | 100 000 000 | a cellák és sorok ismétlésével (`number-columns-repeated` x `number-rows-repeated`) keletkező ODS-cellák |
| `maxSharedStrings` | 10 000 000 | több millió bejegyzéses XLSX megosztottszöveg-táblák |

Rögzített korlátok: az 1 048 576. soron és az XFD (16 384.) oszlopon túli XLSX-cellák; a 16 384-nél
több mezőt tartalmazó CSV-rekord; a 4 096-nál több munkalap; az XLSX-stílusrészben a 65 536-nál több
cella- vagy számformátum; 64 MiB az XLSX munkafüzet-, kapcsolat- és stílusrészeire. Mindegyik
`LimitExceeded` kivételt ad, ahelyett hogy elfogyasztaná a memóriát vagy az időt. A belső részhalmazt
(entitásdeklarációkat) tartalmazó DOCTYPE-ot elutasítja, külső DTD-t és entitást pedig soha nem tölt
be, így nincs XXE és entitáskiterjesztés. A nem biztonságos zip-bejegyzésneveket visszautasítja, az
olvashatatlan fejlécű bejegyzés pedig `CorruptFile` kivételt ad. A korlátok megsértése `LimitExceeded`, a sérült fájl `CorruptFile` kivételt ad;
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
