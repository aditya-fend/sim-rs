<?php

/*
|--------------------------------------------------------------------------
| ICD-10 code catalogue
|--------------------------------------------------------------------------
|
| Used to populate the `display` element on FHIR Condition codings
| (FR-18) and the description shown next to a picked diagnosis
| (ui-spec.md `c-icd10-picker`).
|
| This is a curated subset of codes relevant to regional Type C/D hospital
| practice, not the full WHO ICD-10 browser. FR-18 says codes are "verified
| against standard nomenclature"; a hospital that needs a code outside this
| list gets the code accepted and stored, with the description falling back to
| the bare code rather than being rejected. Swap in a full dictionary here to
| widen coverage — nothing else in the codebase needs to change.
|
*/

return [

    // Digestive
    'K21.0' => 'Gastroesophageal reflux disease with esophagitis',
    'K29.0' => 'Acute gastritis',
    'K29.7' => 'Gastritis, unspecified',
    'K30' => 'Functional dyspepsia',
    'K35.8' => 'Acute appendicitis, unspecified',
    'K52.9' => 'Noninfective gastroenteritis and colitis, unspecified',
    'K80.2' => 'Calculus of gallbladder without cholecystitis',
    'K81.0' => 'Acute cholecystitis',
    'K92.2' => 'Gastrointestinal hemorrhage, unspecified',

    // Respiratory
    'J06.9' => 'Acute upper respiratory infection, unspecified',
    'J18.9' => 'Pneumonia, unspecified organism',
    'J20.9' => 'Acute bronchitis, unspecified',
    'J44.1' => 'Chronic obstructive pulmonary disease with exacerbation',
    'J45.9' => 'Asthma, unspecified',
    'J96.0' => 'Acute respiratory failure',

    // Cardiovascular
    'I10' => 'Essential (primary) hypertension',
    'I20.0' => 'Unstable angina',
    'I21.4' => 'Non-ST elevation (NSTEMI) myocardial infarction',
    'I50.9' => 'Heart failure, unspecified',
    'I63.9' => 'Cerebral infarction, unspecified',
    'I61.9' => 'Non-traumatic intracerebral hemorrhage, unspecified',
    'I95.9' => 'Hypotension, unspecified',

    // Neurological
    'G40.9' => 'Epilepsy, unspecified',
    'G43.9' => 'Migraine, unspecified',
    'G51.0' => "Bell's palsy",
    'G81.0' => 'Hemiplegia',
    'G93.0' => 'Coma, unspecified',

    // Endocrine / metabolic
    'E10.9' => 'Type 2 diabetes mellitus without complications',
    'E11.9' => 'Type 1 diabetes mellitus without complications',
    'E87.6' => 'Hypokalaemia',
    'E88.0' => 'Acidosis',

    // Infectious
    'A09' => 'Infectious gastroenteritis and colitis, unspecified',
    'A15.0' => 'Tuberculosis of lung',
    'B34.9' => 'Viral infection, unspecified',
    'J18.1' => 'Pneumonia due to Streptococcus pneumoniae',
    'A04.7' => 'Enterocolitis due to Clostridioides difficile',

    // Musculoskeletal / injury
    'S06.0' => 'Concussion',
    'S72.0' => 'Fracture of neck of femur',
    'S52.5' => 'Fracture of lower end of radius',
    'M54.5' => 'Low back pain',
    'M54.2' => 'Cervicalgia',
    'T14.9' => 'Injury, unspecified',

    // Genitourinary
    'N20.0' => 'Calculus of kidney',
    'N39.0' => 'Urinary tract infection, site not specified',
    'N17.9' => 'Acute kidney failure, unspecified',

    // Obstetric / gynaecological
    'O80' => 'Single spontaneous delivery',
    'O21.0' => 'Mild hyperemesis gravidarum',
    'N92.6' => 'Excessive and frequent menstruation with regular cycle',

    // Symptoms and signs
    'R07.9' => 'Chest pain, unspecified',
    'R10.4' => 'Lower abdominal pain',
    'R10.9' => 'Unspecified abdominal pain',
    'R50.9' => 'Fever, unspecified',
    'R51.9' => 'Headache, unspecified',
    'R55' => 'Syncope and collapse',
    'R57.0' => 'Cardiovascular collapse',
    'R60.0' => 'Localised oedema',
    'R11.2' => 'Nausea with vomiting, unspecified',
    'R25.2' => 'Tremor, unspecified',
    'R41.0' => 'Disorientation, unspecified',
    'R45.4' => 'Irritability and anger',
    'R53.1' => 'Weakness',
    'R55.9' => 'Syncope and collapse, unspecified',
    'R59.0' => 'Localised enlarged lymph nodes',
    'T78.4' => 'Anaphylactic reaction, unspecified',
    'T78.40' => 'Anaphylactic reaction due to unspecified substance',
    'T63.4' => 'Toxic effect of venom of other arthropods',

    // External causes / circumstances
    'X59.9' => 'Exposure to unspecified factor, unspecified',
    'W01.0' => 'Fall on same level due to slipping',
    'V03.1' => 'Pedestrian injured in collision with pedal cycle',
    'V29.9' => 'Motorcycle rider injured in unspecified traffic accident',

];
