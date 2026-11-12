<?php
date_default_timezone_set('Asia/Manila');
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$clinic = [
    'name'     => 'MSCare Dental Clinic',
    'short'    => 'MSCare',
    'phone'    => '0908 860 2116',
    'phone_tel'=> '+639088602116',
    'facebook' => 'MSCare Dental Clinic',
    'year'     => 2026,
];

$team = [
    ['initials' => 'MC', 'name' => 'Dr. Marilou S. Cariño', 'role' => 'Dentist',
     'bio' => 'Our dentist and the owner of both clinics. She sees patients in Makati and in Manila, so your care stays with one person.'],
    ['initials' => 'NC', 'name' => 'Nina Clemente', 'role' => "Dentist's Assistant",
     'bio' => 'Prepares your visit, supports Dr. Cariño during treatment and helps you with questions at both clinics.'],
];

$branches = [
    'c1' => ['name' => 'Clinic 1 — Makati', 'city' => 'Makati', 'full' => 'MSCare Dental Clinic Makati', 'lines' => ['#7 Moonlite Street', 'Barangay Singkamas', 'Makati City'],
             'address' => '#7 Moonlite Street, Barangay Singkamas, Makati City',
             'days' => ['Mon', 'Wed', 'Fri', 'Sat'], 'days_text' => 'Monday, Wednesday, Friday & Saturday',
             'days_short' => 'Mon, Wed, Fri & Sat', 'tone' => 'teal'],
    'c2' => ['name' => 'Clinic 2 — Manila', 'city' => 'Manila', 'full' => 'MSCare Dental Clinic Manila', 'lines' => ['Burke House No 5 Building', 'Pedro Gil Street', 'Sta. Ana, Manila'],
             'address' => 'Burke House No 5 Building, Pedro Gil Street, Sta. Ana, Manila',
             'days' => ['Tue', 'Thu'], 'days_text' => 'Tuesday & Thursday',
             'days_short' => 'Tue & Thu', 'tone' => 'amber'],
];

$services = [
    ['General Dentistry', 'Check-ups, fillings and preventive care for the whole family.', 'M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z', 'teal'],
    ['Cleaning', 'Professional teeth cleaning to remove plaque and keep your gums healthy.', 'M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8', 'green'],
    ['Orthodontics', 'Treatment to straighten your teeth and correct your bite.', 'M4 20V10M12 20V4M20 20v-7', 'violet'],
    ['Esthetic Dentistry', 'Treatments that improve the look of your smile.', 'M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z', 'amber'],
    ['Prosthodontics', 'Crowns, bridges and dentures to restore missing or damaged teeth.', 'M4 9h16M6 9v6a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V9M9 9V6a3 3 0 0 1 6 0v3', 'teal'],
    ['Root Canal Treatment', 'Treatment to save an infected or badly damaged tooth.', 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 8v4l3 2', 'coral'],
    ['Oral Surgery', 'Extractions and other minor surgical procedures.', 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 8v8M8 12h8', 'violet'],
    ['Braces', 'Fixed braces to align your teeth, with progress tracked visit to visit.', 'M3 9h18M3 15h18M7 6v12M12 6v12M17 6v12', 'green'],
];

$serviceAlt = ['Cleaning' => 'Oral Prophylaxis', 'Root Canal Treatment' => 'Endodontic Treatment'];
$basicServices = ['General Dentistry', 'Cleaning', 'Oral Surgery'];
$serviceCategories = ['Basic Dental Services', 'Specialized Services'];

foreach ($services as &$svc) {
    $svc[5] = in_array($svc[0], $basicServices, true) ? $serviceCategories[0] : $serviceCategories[1];
    $svc[4] = 'images/service-' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $svc[0]), '-')) . '.jpg';
}
unset($svc);

$images = [
    'home'          => 'images/home-team.jpg',
    'about_hero'    => 'images/about-hero.jpg',
    'services_hero' => 'images/services-hero.jpg',
    'dentist'       => 'images/dr-carino.jpg',
    'assistant'     => 'images/nina-clemente.jpg',
];

$about = [
    'hero_title' => 'Dental care with a personal touch',
    'hero_text'  => 'One dentist and one assistant, caring for patients at our clinics in Makati and Manila.',
    'dentist_paragraphs' => [
        'Dr. Marilou S. Cariño is a licensed dentist and the owner of MSCare Dental Clinic. She earned her Doctor of Dental Medicine degree at Centro Escolar University (CEU), Batch ’88, and passed the Dental Licensure Examination in the same year.',
        'She later completed postgraduate studies in Orthodontics and a short course in Implant Dentistry. Dr. Cariño attends to patients at both the Makati and Manila clinics.',
    ],
    'dentist_points' => [
        'Doctor of Dental Medicine, Centro Escolar University (Batch ’88)',
        'Passed the Dental Licensure Examination in the year of graduation',
        'Postgraduate studies in Orthodontics and a course in Implant Dentistry',
    ],
    'assistant_paragraphs' => [
        'Nina Clemente has served as Dr. Cariño’s dental assistant for nine years. She prepares patients for their visits, assists the dentist during treatment, and answers patient inquiries at both clinics.',
        'She is currently in her final year of Dentistry studies.',
    ],
    'vision'  => 'To be a dental clinic where every patient feels cared for and every smile is looked after.',
    'mission' => 'To provide dental care for the whole family at our Makati and Manila clinics, keeping a record of every visit so each treatment continues from the last.',
    'values'  => ['MSC + Care', 'MSCare takes its name from the initials of our dentist, MSC, joined with the word care.'],
];

function icon($path, $size = 22) {
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$path.'"/></svg>';
}
function map_src($address) {
    return 'https://www.google.com/maps?q=' . urlencode($address) . '&output=embed';
}

function str_len($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

$slots = ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];   // clinic 9-5, 1 hour per patient, 12-1 lunch

$faqs = [
    ['Which branch should I choose?', 'Pick the one that is closer to you or open on the day you prefer. Makati is open Monday, Wednesday, Friday and Saturday. Manila is open Tuesday and Thursday. Dr. Cariño and Nina work at both.'],
    ['Is my appointment confirmed once I submit?', 'Not yet. Your request goes to the clinic, and the team will contact you to confirm the schedule.'],
    ['Do you see children?', 'Our general dentistry and cleaning services are for the whole family. Add your child’s age in the comments.'],
    ['I’m already a patient. What should I include?', 'Choose Yes under current patient and use the same name and mobile number you gave the clinic before.'],
    ['What if I need to be seen today?', 'Call a branch directly at ' . $clinic['phone'] . ' for urgent concerns.'],
];

$ui = [
    'pin'      => 'M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    'phone'    => 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
    'clock'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
    'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
    'compass'  => 'M3 11l18-8-8 18-2-8-8-2z',
    'send'     => 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
    'arrow'    => 'M5 12h14M13 6l6 6-6 6',
    'back'     => 'M19 12H5M11 18l-6-6 6-6',
    'check'    => 'M5 12l5 5L20 7',
    'search'   => 'M21 21l-4.3-4.3M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z',
    'external' => 'M7 17L17 7M8 7h9v9',
    'pencil'   => 'M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4',
    'user'     => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c.8-4 3.8-6 8-6s7.2 2 8 6',
    'lock'     => 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
    'mail'     => 'M3 5h18v14H3zM3 7l9 6 9-6',
    'grid'     => 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
    'history'  => 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 8v4l3 2',
    'file'     => 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h6',
    'logout'   => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    'plus'     => 'M12 5v14M5 12h14',
    'eye'      => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
];

function directions_url($address) {
    return 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($address);
}

// Ready-made questions shown in the patient chatbot popup. Clicking one gives the answer instantly (edit the text here).
$bot_faqs = [
    ['How can I book an appointment?',
     'You can book an appointment through our MSCare website. Simply log in to your patient account, go to the Appointments section, select your preferred dental service, choose an available date and time, and submit your request. Please wait for confirmation from our clinic.'],
    ['What dental services do you offer?',
     'MSCare Dental Clinic offers various dental services to help maintain your oral health. These may include dental cleaning, tooth extraction, tooth filling, teeth whitening, oral examinations, and other dental treatments. Please visit our Services page for the complete list.'],
    ['How much does a dental treatment cost?',
     'The cost of dental treatment depends on the type of service and your dental needs. You may visit our Services page to check the available services and their corresponding prices. For an accurate quotation, please contact our clinic.'],
    ["What are your clinic's operating hours?",
     "Our clinic's operating hours and available appointment schedules can be viewed on our website. You may also contact our clinic directly to confirm our availability before visiting."],
    ['How can I cancel or reschedule my appointment?',
     'You can manage your appointment through your patient account. Go to the Appointments section, select your scheduled appointment, and check the available options for cancellation or rescheduling. You may also contact our clinic for assistance.'],
];
