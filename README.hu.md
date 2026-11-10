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

- A más kódolású CSV-t veszteségmentesen alakítja UTF-8-ra: UTF-16, UTF-32, valamint egybájtos
  kódlapok, például Windows-1250 vagy ISO-8859-2. A kódlapban nem definiált bájt (például a `0x81` a
  Windows-1250-ben) `U+FFFD` karakter lesz, és nem hiúsítja meg az olvasást. Más többbájtos kódolás
  (Shift_JIS, GBK, Big5, EUC-KR) `InvalidArgumentException` kivételt ad; az ilyen fájlt előbb
  UTF-8-ra kell alakítani.
- Az UTF-16-ot és az UTF-32-t bájtsorrend-jelzővel (BOM) és anélkül is felismeri; BOM nélkül a
  nullbájtok helyzetéből, ami túlnyomórészt latin betűs szövegnél működik. A bájtsorrend nélküli
  `csvEncoding` (`UTF-16`, `UCS-2`, `UTF-32`, `UCS-4`) a sorrendet a BOM-ból, BOM nélkül a felismert
  sorrendből veszi, végső esetben big-endiant használ; az `UTF-16LE` és a hasonló nevek pontosan úgy
  érvényesülnek, ahogy meg vannak adva.
- Az Excel for Mac CSV-exportját is olvassa. A Mac Central European kódlapot akkor ismeri fel, ha
  a szövegben elég magyar `á` és `é` van ahhoz, hogy megkülönböztesse a Windows-1250-től, amely az
  alapértelmezés marad; más szövegnél adjuk meg: `csvEncoding: 'MacCentralEurope'`. A csak
  kocsivisszával tördelt „CSV Mac” formátumot rekordokra bontja (akkor ismeri fel, ha az idézett
  mezőkön kívül van magányos kocsivissza és nincs soremelés); az ilyen fájlban az idézett mezőn
  belüli kocsivissza `\n` lesz.
- A `maxCellBytes` a nyers CSV-bájtokat számolja, a határoló idézőjelekkel együtt. Kis korlát a
  szokásos soroknál nem lassít; a korláttal nagyjából egyező vagy hosszabb sorokat bájtonként
  ellenőrzi, ezek kb. kétszer lassabban olvashatók.

Hiányzó vagy olvashatatlan fájlnál minden formátum `SpreadsheetException` kivételt ad, akkor is, ha
közvetlenül egy olvasóosztályt használunk. Üres (0 bájtos) fájlnál `UnsupportedFormat` keletkezik;
`Format::Csv` megadásával üres CSV-ként olvasható. A zip-archívumként kezdődő, de meg nem nyitható
(például csonkán feltöltött) fájl `CorruptFile` kivételt ad. Az `.xlsb`, a jelszóval védett
Office-fájl, a Numbers és a PDF `UnsupportedFormat` kivételt ad, az üzenet megnevezi a típust. A NUL
bájt nélküli rövid bináris fájl nem különböztethető meg a szövegtől, ezért CSV-ként olvasódik.

### Régi XLS-fájlok olvasása

A bináris `.xls` munkafüzeteket a tartalmuk alapján ismeri fel a könyvtár (`Format::Xls`), és az opcionális
[phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) csomaggal olvassa
(`composer require phpoffice/phpspreadsheet`; nélküle az XLS olvasása `UnsupportedFormat` kivételt ad). A sorok,
a munkalap-kiválasztás, a `rowsWithHeader()` és az értéktípusok a többi formátummal egyezően működnek, az 1904-es
dátumrendszert is beleértve. Ezzel szemben az **XLS-olvasás nem folyamatos**: a teljes munkafüzet a memóriába
kerül. A munkalapok méretét előbb olvassa ki, és az a munkafüzet, amelynek lapjain több cella van a `maxCells`
értéknél, a betöltés előtt `LimitExceeded` kivételt kap. XLS-fájl nem írható.

### Fájl vizsgálata

A `FileInspector::inspect($path)` minden munkalap első 20 nem üres sorát végignézi (a `scanRows`-szal
állítható), és megadja a formátumot, munkalaponként a tippelt fejlécsort (valódi, 1-től induló sorszám vagy
`null`), az oszlopszámot és a mintasorokat, valamint a `firstDataSheet`-et, az első látható, adatot tartalmazó
munkalapot. Fejlécnek az első olyan átvizsgált sor számít, amelyben legalább két nem szám jellegű szöveges
cella van, és amelyet nem üres sor követ. A `FileInspector::countCells($path, stopAbove: 500_000)` a nem üres
cellákat számolja, és a határ átlépésekor azonnal megáll (`exceeded`), így egy túlméretes munkalap az egész
beolvasása nélkül kiszűrhető.

```php
$inspection = FileInspector::inspect('contacts.xlsx');
$sheet = $inspection->sheets[$inspection->firstDataSheet ?? 0];
$header = $sheet->headerRow; // pl. 3
```

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
- A munkalapnevek ellenőrzése (hossz, tiltott karakterek, az XML-ben nem tárolható karakterek,
  például az U+FFFE, kis-nagybetűtől független duplikátum) még az írás megkezdése előtt megtörténik.
- A `Style` megváltoztathatatlan; az azonos stílusok egyetlen bejegyzésen osztoznak a fájlban.
- A lebegőpontos számok a legrövidebb, ugyanarra a számra visszaolvasható alakban íródnak (a `0.1`
  `0.1` marad, az `1.0E+25` kitevős), a `precision` és a `serialize_precision` beállítástól
  függetlenül; a `-0.0` `0`-ként íródik. Az egész számok pontosan íródnak ki, de az Excel és a
  LibreOffice dupla pontosságú lebegőpontos számként tárolja őket: a 2^53 (9 007 199 254 740 992)
  fölötti egészek pontatlanná válnak, és az Excel 15 értékes jegyet mutat, ezért az azonosítókat és
  számlaszámokat szövegként írjuk.
- Az oszlopszélesség az alapbetűtípus (11 pontos Calibri) karaktereiben értendő. A cella belső
  margóját az OOXML-szabvány szerint hozzáadjuk, így a Windowsos Excel pontosan a megadott
  szélességet mutatja; az Excel for Mac 0,17-dal keskenyebbet (a 32-ből 31,83 lesz, Excel for Mac
  16-tal ellenőrizve).
- A dátumot tartalmazó oszlop a leghosszabb dátumához illő szélességet kap, ha nem adunk meg
  sajátot: `yyyy-mm-dd` esetén 11-et, `yyyy-mm-dd hh:mm:ss` esetén 20-at, a hosszú hónap- és
  napnevet 10 karakternek számolva. Enélkül az Excel `#####`-t mutatna az alapértelmezett oszlopnál
  szélesebb dátum helyén. A többi oszlop szélessége nem igazodik a tartalomhoz.
- A `close()` a kész fájlt a célútvonalra nevezi át, a meglévő fájlt figyelmeztetés nélkül
  felülírva. Ha egy korábbi hiba megszakította az írást, a `close()` `LogicException` kivételt ad, és
  nem keletkezik fájl.

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
karakterrel kezdődő szöveges érték elé `'` kerül, így a táblázatkezelő szövegként mutatja, és nem
futtatja. Az aposztróf látható marad: az Excel a kezdő `'` jelet csak akkor rejti el, ha cellába
gépelik, CSV-fájlból érkezve nem (Excel for Mac 16-tal ellenőrizve). Alapértelmezésben ki van
kapcsolva, mert megváltoztatja az adatot (a `-5` szövegből `'-5` lesz); felhasználói adatot
tartalmazó exportnál kapcsoljuk be. A `Cell::formula()`-val átadott értékeket nem módosítja.

Az Excel a számnak vagy dátumnak látszó CSV-mezőket is átalakítja: a `00123`-ból `123`, az
`1e5`-ből `1,00E+05`, a `2026-01-15`-ből dátum lesz. Ezt CSV-ben nem lehet megakadályozni; ha az
ilyen szövegnek meg kell maradnia, XLSX-et írjunk.

## Kompatibilitás irodai alkalmazásokkal

macOS 15.7-en (magyar területi beállítással) ellenőrizve Microsoft Excel for Mac 16.114-gyel, Apple
Numbersszel és LibreOffice 26.8-cal, a `tests/office/build-samples.php` mintakészletével és a
mellette lévő ellenőrzőkkel (Excelhez és Numbershöz AppleScript, LibreOffice-hoz
`libreoffice-inspect.sh`).

Excel:

- Az XLSX és az ODS (sima és stílusos) javítási kérdés nélkül nyílik meg. A cellák pozíciója és
  típusa helyes, a null-rések, dátumok, logikai értékek, többsoros szövegek és a számnak vagy
  képletnek látszó szövegek (`00123`, `1e5`, `=1+1`) is. XLSX-ben látszik a szám- és dátumformátum, az
  oszlopszélesség, a rögzített fejlécsor és az autoszűrő; a képleteket megnyitáskor mindkét
  formátumban kiszámolja.
- ODS Excelben: az autoszűrő látszik, a rögzített sor nem, mert az Excel ODS-támogatása nem ismeri a
  rögzített ablaktáblát: a LibreOffice-szal mentett ODS-ben sem jeleníti meg.
- A dátumoszlopok legalább olyan szélesek, amilyenre az Excel automatikus igazítása állítja őket,
  így egyik dátum helyén sem jelenik meg `#####`.
- A `delimiter: ';', bom: true` beállítással írt CSV oszlopokra bontva, helyes ékezetekkel nyílik
  meg; `,` elválasztóval magyar rendszeren nem bontja oszlopokra.
- Az Excel által mentett fájlok (XLSX, valamint Mac kódlapú, CRLF vagy CR sorvégű CSV) helyes
  értékekkel és típusokkal olvashatók vissza.

LibreOffice:

- Az XLSX és az ODS helyes értékekkel, típusokkal és formátumokkal nyílik meg; a rögzített fejlécsor
  és az autoszűrő mindkét formátumban látszik, a dátumoszlopok pedig legalább olyan szélesek, mint a
  Calc optimális szélessége.

Apple Numbers:

- Az XLSX és a CSV helyes értékekkel, típusokkal, szám- és dátumformátummal, kiszámolt képletekkel
  nyílik meg.
- ODS-fájlt a Numbers egyáltalán nem tud megnyitni, és XLSX-ből (az Excel saját fájljaiból is)
  nem jeleníti meg a rögzített sort.

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
| `maxCellBytes` | 1 MiB | korlátlanul növő cella vagy CSV-mező: ODS szóközsorozatok (`text:s`), minden XLSX-szöveg (megosztott, beágyazott, képleteredmény, típus nélküli), elszabadult idézőjel utáni CSV-mező |
| `maxCells` | 100 000 000 | a cellák és sorok ismétlésével (`number-columns-repeated` x `number-rows-repeated`) keletkező ODS-cellák |
| `maxSharedStrings` | 10 000 000 | több millió bejegyzéses XLSX megosztottszöveg-táblák |

Rögzített korlátok: az 1 048 576. soron túli nem üres sorok (az ezen túli üres sorokat kihagyja; az
ODS ismétlésszámai akárhány jegyűek, a korlátokhoz mérődnek) és az XFD (16 384.) oszlopon túli
XLSX-cellák; a 16 384-nél
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
