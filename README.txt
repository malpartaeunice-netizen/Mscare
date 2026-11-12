MSCare Dental Clinic - patient website (PHP)

Run it
  1. Copy this folder into C:\xampp\htdocs\  (name it "mscare_users")
  2. Start Apache in XAMPP
  3. Open http://localhost/mscare_users/

Pages: index.php (Home), about.php, services.php, contact.php, book.php (Book Appointment)
Clinic info (phone, addresses, days, services, team) lives in config.php - edit it once and every page updates.
Contact messages and appointment requests are saved in PostgreSQL (see below).

Images
  All pictures live in the images folder as .jpg files. Replace any file with your own photo using the same file name and the site updates.
    home-team.jpg            Home page photo
    about-hero.jpg           About Us banner (also used behind Vision / Mission / Values)
    dr-carino.jpg            Dr. Cariño portrait
    nina-clemente.jpg        Nina Clemente portrait
    services-hero.jpg        Services banner
    service-<name>.jpg       One photo per service (service-general-dentistry.jpg, service-cleaning.jpg,
                             service-orthodontics.jpg, service-esthetic-dentistry.jpg, service-prosthodontics.jpg,
                             service-root-canal-treatment.jpg, service-oral-surgery.jpg, service-braces.jpg)
  The current pictures are example placeholders.
  Image file names and the About page text (dentist story, vision, mission, values) are set in config.php.


PostgreSQL setup
  1. Install PostgreSQL and create the database + tables:
        psql -U postgres -f database/schema.sql
     (or in pgAdmin: open database/schema.sql in the Query Tool and run it; run the CREATE DATABASE
      line first, connect to "mscare", then run the rest)
  2. In XAMPP open php.ini (XAMPP Control Panel > Apache > Config > PHP (php.ini)) and make sure these
     two lines have no semicolon in front, then restart Apache:
        extension=pdo_pgsql
        extension=pgsql
  3. Open includes/db.php and set your host / port / database / user / password
     (defaults: localhost, 5432, mscare, postgres, 0519).
  4. database/queries.sql has ready-made statements to view requests, confirm / cancel / reschedule,
     and read contact messages.

  Tables: branches, services, appointments, contact_messages.
  A date + time can be held only once (one dentist for both clinics); cancelling a request frees the slot.

Email verification (sender setup - no code editing)
  1. Log in to the admin site > Settings > Email Sender.
  2. Enter the clinic's sender email + its App Password (Gmail: myaccount.google.com/apppasswords), then "Save & test".
  3. Done. Patients can register with ANY email address and will receive their 6-digit code from that sender.

AI chatbot (Gemini) for Messages
  1. Get a free key at https://aistudio.google.com/apikey and paste it in includes/ai_config.php (GEMINI_API_KEY).
  2. Run database/mscare_full_ai.sql (or just database/messages_chat.sql on an existing database) in pgAdmin.
  3. php.ini: extension=curl must be enabled (restart Apache). If you see "SSL certificate problem" in the Apache error log,
     download cacert.pem and set curl.cainfo in php.ini.
  4. What it does: patient asks -> AI answers from the info in config.php. If it cannot answer (prices, medical advice,
     appointment changes, emergencies...), it tells the patient the staff will reply and the message stays UNREAD with a
     "Needs reply" badge in the admin Messages inbox. If a human admin replied in the last 12 hours, the AI stays silent.
  5. To teach the bot more (prices, what to bring, payment methods) edit ai_knowledge() in includes/ai_chat.php.
  6. To turn it off: leave GEMINI_API_KEY as the placeholder or empty.
