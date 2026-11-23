<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Import Participants & Pets (importData.php): reading spreadsheet rows,
 * applying the Register Participant / Register Pet rules, and grouping pet
 * rows into households.
 */
final class ImportParseTest extends TestCase
{
    private const LOCATIONS = ['Empower', 'Senior Center', 'Home Delivery'];

    /** Build households from a header row and data rows, as importData.php does. */
    private function build(array $header, array $rows, string $defaultLocation = ''): array
    {
        [$map, $missing] = import_map_headers($header);
        $this->assertSame([], $missing, 'required columns found');
        return import_build_households($map, $rows, 2, $defaultLocation, self::LOCATIONS, '2026-11-22')['households'];
    }

    private const HEADER = ['First Name', 'Last Name', 'Street Address', 'Phone', 'Location',
        'Pet Name', 'Species', 'Breed', 'Age (years)', 'Weight (lbs)'];

    public function testHeadersMatchIgnoringCaseSpacingAndCommonAliases(): void
    {
        [$map, $missing] = import_map_headers(['FIRST NAME', 'last_name', 'Address', 'Phone Number', 'Rabies Shot', 'Total Pets', '']);
        $this->assertSame([0 => 'first_name', 1 => 'last_name', 2 => 'street_address', 3 => 'phone', 4 => 'rabies_status'], $map);
        $this->assertSame([], $missing);
    }

    public function testMissingRequiredColumnsAreReported(): void
    {
        [, $missing] = import_map_headers(['First Name', 'Phone']);
        $this->assertSame(['last_name'], $missing);
    }

    public function testRowsWithTheSameNameAndPhoneBecomeOneHousehold(): void
    {
        $households = $this->build(self::HEADER, [
            ['Ann', 'Lee', '1 Elm St', '(540) 555-0199', 'Empower', 'Max', 'Dog', 'Lab', '3', '45'],
            ['Bob', 'Ray', '9 Oak Ave', '5405550111', 'Empower', '', '', '', '', ''],
            ['ann', 'LEE', '1 Elm St', '540-555-0199', 'Empower', 'Luna', 'Cat', 'DSH', '2', '9'],
        ]);
        $this->assertCount(2, $households);
        $this->assertSame([2, 4], $households[0]['rows']);
        $this->assertSame(['Max', 'Luna'], array_column($households[0]['pets'], 'name'));
        $this->assertSame([], $households[1]['pets'], 'blank pet columns mean no pet');
        $this->assertSame([], $households[0]['errors']);
    }

    public function testValuesAreNormalizedTheWayStaffTypeThem(): void
    {
        $header = ['First Name', 'Last Name', 'Street Address', 'Phone', 'Location', 'Registration Date', 'ZIP',
            'Pet Name', 'Species', 'Breed', 'Sex', 'Age', 'Weight', 'Spayed/Neutered', 'Rabies', 'Food Type'];
        [$h] = $this->build($header, [
            ['Ann', 'Lee', '1 Elm St', '1-540-555-0199', 'senior center', 46238.0, 2401.0,
             'Max', 'puppy', 'Lab', 'm', '6 mo', '45 lbs', 'y', '', 'DRY'],
        ]);
        $this->assertSame([], $h['errors']);
        $p = $h['participant'];
        $this->assertSame('5405550199', $p['phone']);
        $this->assertSame('Senior Center', $p['location']);
        $this->assertSame('2026-08-04', $p['registration_date'], 'Excel date number');
        $this->assertSame('02401', $p['zip'], 'leading zero restored');
        $pet = $h['pets'][0];
        $this->assertSame(['Dog', 'Male', '0.5', '45', 'Yes', 'Unknown', 'dry'],
            [$pet['species'], $pet['sex'], $pet['age_years'], $pet['weight_lbs'], $pet['spay_neuter_status'], $pet['rabies_status'], $pet['food_type']]);
    }

    public function testAWholeAddressInOneCellIsSplit(): void
    {
        [$h] = $this->build(['First Name', 'Last Name', 'Address', 'Phone', 'Location'], [
            ['Ann', 'Lee', '412 Princess Anne St, Fredericksburg, VA 22401', '5405550199', 'Empower'],
        ]);
        $p = $h['participant'];
        $this->assertSame(['412 Princess Anne St', 'Fredericksburg', 'VA', '22401'], [$p['street_address'], $p['city'], $p['state'], $p['zip']]);
    }

    public function testTheDefaultLocationFillsBlankCellsOnly(): void
    {
        $households = $this->build(self::HEADER, [
            ['Ann', 'Lee', '1 Elm St', '5405550199', '', '', '', '', '', ''],
            ['Bob', 'Ray', '9 Oak Ave', '5405550111', 'Empower', '', '', '', '', ''],
        ], 'Home Delivery');
        $this->assertSame(['Home Delivery', 'Empower'], array_column(array_column($households, 'participant'), 'location'));

        [$h] = $this->build(self::HEADER, [['Ann', 'Lee', '1 Elm St', '5405550199', '', '', '', '', '', '']]);
        $this->assertStringContainsString('Location is missing', $h['errors'][0][1]);
    }

    public function testOnlyANameOrAnUnknownLocationStopsAHousehold(): void
    {
        [$h] = $this->build(['First Name', 'Last Name', 'Street Address', 'Phone', 'Email', 'Location'], [
            ['', 'Lee', '1 Elm St', '540-555-0199', '', 'Mars'],
        ]);
        $messages = implode("\n", array_column($h['errors'], 1));
        $this->assertStringContainsString('First name is missing', $messages);
        $this->assertStringContainsString('Location "Mars"', $messages);
    }

    public function testMissingOrUnreadableContactDetailsAreWarningsKeptInTheNotes(): void
    {
        [$h] = $this->build(['First Name', 'Last Name', 'Street Address', 'Phone', 'Email', 'Location', 'Registration Date'], [
            ['Ann', 'Lee', '', '540-555-01999', 'not-an-email', 'Empower', '2027-01-01'],
        ]);
        $this->assertSame([], $h['errors']);
        $warnings = implode("\n", array_column($h['warnings'], 1));
        $this->assertStringContainsString('No address', $warnings);
        $this->assertStringContainsString('not a 10-digit number', $warnings);
        $this->assertStringContainsString('not a valid e-mail', $warnings);
        $this->assertStringContainsString('in the future', $warnings);
        $p = $h['participant'];
        $this->assertSame(['', '', '2026-11-22'], [$p['phone'], $p['email'], $p['registration_date']]);
        $this->assertSame("Phone on sheet: 540-555-01999\nEmail on sheet: not-an-email", $p['notes']);
    }

    public function testTheFirstOfTwoPhoneNumbersIsUsed(): void
    {
        [$h] = $this->build(self::HEADER, [['Ann', 'Lee', '1 Elm St', '540-555-0199 or 540-555-0100', 'Empower', '', '', '', '', '']]);
        $this->assertSame('5405550199', $h['participant']['phone']);
        $this->assertSame([], $h['warnings']);
    }

    public function testPetsMissingBreedWeightOrAgeAreImportedBlankWithAWarning(): void
    {
        // Older records often lack them; Edit Pet asks for them later (FR-027)
        [$h] = $this->build(self::HEADER, [['Ann', 'Lee', '1 Elm St', '5405550199', 'Empower', 'Max', 'Dog', '', '', 'small']]);
        $this->assertSame([], $h['errors']);
        $pet = $h['pets'][0];
        $this->assertSame([null, null, null], [$pet['breed'], $pet['age_years'], $pet['weight_lbs']]);
        $this->assertSame('Weight on sheet: small', $pet['notes']);
        $warnings = implode("\n", array_column($h['warnings'], 1));
        $this->assertStringContainsString('Dog "Max": weight "small" couldn\'t be read', $warnings);
        $this->assertStringContainsString('Dog "Max": no breed, age on the sheet.', $warnings);
    }

    public function testTheChsSheetLayoutWithDogAndCatColumnGroups(): void
    {
        // As in PetPantryMaster.xlsx: a "Dogs" count then the dog's columns,
        // a "Cats" count then the cat's, "Other", and extra pets on extra rows
        $header = ['Date', 'Last Name ', 'First Name ', 'Address', 'Phone Number', 'email',
            'Dogs', 'Name ', 'Breed', 'Sex', 'Spayed/ Nuetered', 'Rabies Shot', 'Age', 'Weight ', 'Special Needs',
            'Cats', 'Name ', 'Breed', 'Sex', 'Spayed/ Nuetered', 'Rabies Shot ', 'Age', 'Weight ', 'Special Needs',
            'Other ', 'Total Pets', 'Notes'];
        [$map, $missing] = import_map_headers($header);
        $this->assertSame([], $missing);
        $this->assertSame('Dog:pet_name', $map[7]);
        $this->assertSame('Cat:spay_neuter', $map[19]);
        $this->assertSame('other_pets', $map[24]);
        $this->assertSame('notes', $map[26]);
        $this->assertArrayNotHasKey(6, $map, 'the Dogs count is not a pet');

        $rows = [
            [46238.0, 'Lee', 'Ann', '1 Elm St, Culpeper, VA 22701', '540-555-0199', 'none',
             2, 'Max', 'Lab', 'Male', 'Yes', '', '1 yr 6 mo', '40-50 lbs', 'no',
             1, 'Luna', '', 'Female', 'needs', 'needs', '< 1 yr', '', '',
             '1 bird', 4, 'Gate code 12'],
            [null, 'Lee', 'Ann', '', '540-555-0199', '',
             null, 'Rex', 'Beagle', 'Male', 'No', 'Yes', '6 yrs', '25 lbs +', 'Allergies',
             null, '', '', '', '', '', '', '', '', '', '', ''],
        ];
        $result = import_build_households($map, $rows, 2, 'Empower', self::LOCATIONS, '2026-11-22');
        $this->assertCount(1, $result['households']);
        $h = $result['households'][0];
        $this->assertSame([], $h['errors']);
        $p = $h['participant'];
        $this->assertSame(['1 Elm St', 'Culpeper', '', 'Empower', '2026-08-04'], [$p['street_address'], $p['city'], $p['email'], $p['location'], $p['registration_date']]);
        $this->assertSame("Gate code 12\nOther pets: 1 bird", $p['notes']);

        [$max, $luna, $rex] = $h['pets'];
        $this->assertSame(['Max', 'Dog', '1.5', '45', null], [$max['name'], $max['species'], $max['age_years'], $max['weight_lbs'], $max['special_medical_needs']]);
        $this->assertSame("Weight on sheet: 40-50 lbs", $max['notes']);
        $this->assertSame(['Luna', 'Cat', 'No', 'No', '0.5'], [$luna['name'], $luna['species'], $luna['spay_neuter_status'], $luna['rabies_status'], $luna['age_years']]);
        $this->assertSame(['Rex', 'Dog', '25', 'Allergies', 3], [$rex['name'], $rex['species'], $rex['weight_lbs'], $rex['special_medical_needs'], $rex['row']]);
    }

    public function testOtherSpeciesAreKeptInThePetNotes(): void
    {
        [$h] = $this->build(self::HEADER, [['Ann', 'Lee', '1 Elm St', '5405550199', 'Empower', 'Hop', 'Rabbit', 'Lop', '2', '4']]);
        $this->assertSame([], $h['errors']);
        $this->assertSame('Other', $h['pets'][0]['species']);
        $this->assertSame('Species: Rabbit', $h['pets'][0]['notes']);
    }

    public function testAgesAndDatesInOtherFormats(): void
    {
        $this->assertSame('2', import_age_years('2 yrs'));
        $this->assertSame('0.2', import_age_years('8 weeks'));
        $this->assertSame('0.1', import_age_years('2 weeks'), 'very young pets get the youngest allowed age');
        $this->assertSame('ten', import_age_years('ten'), 'unreadable text is left for the age check to reject');
        $this->assertSame('2.5', import_age_years('2-3 yrs'));
        $this->assertSame('5', import_age_years('5yrd'), 'typos of "yrs"');
        $this->assertSame('9 lbs', import_age_years('9 lbs'), 'a weight typed in the age column');
        $this->assertSame('45', import_weight('40-50 lbs'));
        $this->assertSame('50', import_weight('50 lbs +'));
        $this->assertSame('small', import_weight('small'));
        $this->assertSame('2026-08-04', import_date('8/4/2026'));
        $this->assertSame('2026-08-04', import_date('8/4/26'));
        $this->assertFalse(import_date('13/45/2026'));
    }

    public function testBlankRowsAreSkipped(): void
    {
        [$map] = import_map_headers(self::HEADER);
        $result = import_build_households($map, [[], ['', null, ' '], ['Ann', 'Lee', '1 Elm St', '5405550199', 'Empower']], 2, '', self::LOCATIONS);
        $this->assertSame(2, $result['skipped_blank']);
        $this->assertSame([4], $result['households'][0]['rows']);
    }

    public function testCsvFilesFromExcelAreRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        // Excel's "CSV UTF-8" starts with a byte order mark
        file_put_contents($file, "\xEF\xBB\xBFFirst Name,Last Name\r\n\"José\",\"O'Neil, Jr\"\r\n");
        $this->assertSame([['First Name', 'Last Name'], ['José', "O'Neil, Jr"]], import_read_csv($file));
        // Plain "CSV" from Excel on Windows is Windows-1252, often with semicolons
        file_put_contents($file, "First Name;Last Name\r\nJos\xE9;Lee\r\n");
        $this->assertSame([['First Name', 'Last Name'], ['José', 'Lee']], import_read_csv($file));
        unlink($file);
    }

    public function testTheTemplateIsAnXlsxTheImportCanReadBack(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        import_write_template($file, ['Empower']);
        $rows = import_read_xlsx($file);
        unlink($file);
        $this->assertCount(1, $rows, 'only the header row');
        [, $missing] = import_map_headers($rows[0]);
        $this->assertSame([], $missing, 'every header, with its * marker, maps back to its column');
        $this->assertSame('First Name *', $rows[0][0]);
    }

    public function testXlsxCellsAreReadWithoutPhpSpreadsheet(): void
    {
        // Shared strings, an inline string, a number, a date number, a gap
        // before column D, and an empty row 2, as Excel writes them
        $file = tempnam(sys_get_temp_dir(), 'import');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId7" Target="/xl/worksheets/data.xml" Type="worksheet"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<si><t>Name</t></si><si><r><t>Jo</t></r><r><t>sé</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Age</t></is></c></row>'
            . '<row r="3"><c r="A3" t="s"><v>1</v></c><c r="B3"><v>4.5</v></c><c r="D3"><v>46238</v></c><c r="E3" t="b"><v>1</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
        $rows = import_read_xlsx($file);
        unlink($file);
        $this->assertSame([['Name', 'Age'], [], ['José', 4.5, null, 46238.0, true]], $rows);
    }
}
