<?php
/** Add extra fictional demonstration data. Command line: php scripts/add_sample_data.php "A-Strong-Demo-Password" */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__).'/vendor_autoload.php';
$password=(string)($argv[1]??'');
if (strlen($password)<10 || strlen($password)>72) { fwrite(STDERR,"Provide a demo password of 10-72 characters.\n"); exit(1); }
try {
    $pdo=db();
    $pdo->beginTransaction();
    $users=[
        ['Campus Demo Admin 2','demo.admin2@example.test','DEMO/ADMIN/02','University-wide','admin'],
        ['Campus Demo Admin 3','demo.admin3@example.test','DEMO/ADMIN/03','University-wide','admin'],
        ['Campus Demo Admin 4','demo.admin4@example.test','DEMO/ADMIN/04','University-wide','admin'],
        ['Campus Demo Admin 5','demo.admin5@example.test','DEMO/ADMIN/05','University-wide','admin'],
        ['Campus Demo Sports Organizer','demo.sports@example.test','DEMO/LEADER/03','Faculty of Applied Science','club_leader'],
        ['Campus Demo Student Three','demo.student3@example.test','DEMO/STUDENT/03','Faculty of Applied Science','student'],
        ['Campus Demo Student Four','demo.student4@example.test','DEMO/STUDENT/04','Faculty of Technological Studies','student'],
    ];
    foreach($users as [$name,$email,$studentNo,$faculty,$role]) {
        if (!row('SELECT id FROM users WHERE email=?',[$email])) {
            execute('INSERT INTO users(full_name,email,student_number,faculty,role,password_hash,status) VALUES(?,?,?,?,?,?,\'active\')',[$name,$email,$studentNo,$faculty,$role,password_hash($password,PASSWORD_DEFAULT)]);
        }
    }
    $leader=row('SELECT id FROM users WHERE email=?',["demo.sports@example.test"]);
    $admin=row('SELECT id FROM users WHERE email=?',["demo.admin2@example.test"]);
    $student3=row('SELECT id FROM users WHERE email=?',["demo.student3@example.test"]);
    $student4=row('SELECT id FROM users WHERE email=?',["demo.student4@example.test"]);
    $clubRows=[
        ['University Sports Club','Sports','University-wide','A sample sports club for friendly competitions, fitness sessions and participation in campus sporting activities.',$leader['id']],
        ['Science and Research Circle','Academic','Faculty of Applied Science','A student community for research presentations, science talks and collaborative academic discussions.',null],
    ];
    foreach($clubRows as [$name,$cat,$faculty,$description,$organizer]) {
        $slug=slugify($name);
        if (!row('SELECT id FROM clubs WHERE slug=?',[$slug])) {
            execute("INSERT INTO clubs(name,slug,description,category,faculty,leader_user_id,status) VALUES(?,?,?,?,?,?,'approved')",[$name,$slug,$description,$cat,$faculty,$organizer]);
        }
    }
    $sports=row('SELECT id FROM clubs WHERE slug=?',[slugify('University Sports Club')]);
    $science=row('SELECT id FROM clubs WHERE slug=?',[slugify('Science and Research Circle')]);
    foreach([[$sports['id'],$student3['id'],'approved'],[$science['id'],$student4['id'],'pending'],[$sports['id'],$student4['id'],'pending']] as [$club,$user,$status]) {
        if (!row('SELECT id FROM club_memberships WHERE club_id=? AND user_id=?',[$club,$user])) {
            execute('INSERT INTO club_memberships(club_id,user_id,status) VALUES(?,?,?)',[$club,$user,$status]);
        }
    }
    foreach([['Sports','Sports events and tournaments'],['Seminar','Academic talks and seminars'],['Workshop','Hands-on learning']] as [$name,$desc]) {
        execute('INSERT IGNORE INTO event_categories(name,description) VALUES(?,?)',[$name,$desc]);
    }
    foreach([['University Playground','University of Vavuniya',150],['Science Lecture Hall','Faculty of Applied Science',80]] as [$name,$location,$capacity]) {
        execute('INSERT IGNORE INTO venues(name,location,capacity) VALUES(?,?,?)',[$name,$location,$capacity]);
    }
    $samples=[
        ['Student Sports Day',$sports['id'],$leader['id'],'Sports','University Playground',12,'An example campus sports day with friendly student activities and inter-club participation.','approved'],
        ['Introduction to Research Methods',$science['id'],$admin['id'],'Seminar','Science Lecture Hall',19,'A sample academic talk introducing research questions, literature reviews and responsible student research.','approved'],
        ['Campus Science Discussion',$science['id'],$admin['id'],'Workshop','Science Lecture Hall',26,'A proposed student discussion workshop about scientific methods and undergraduate academic projects.','pending'],
    ];
    foreach ($samples as [$title,$clubId,$creator,$cat,$venue,$days,$desc,$status]) {
        if (row('SELECT id FROM events WHERE club_id=? AND title=?',[$clubId,$title])) continue;
        $category=row('SELECT id FROM event_categories WHERE name=?',[$cat]);
        $v=row('SELECT id,capacity FROM venues WHERE name=?',[$venue]);
        $date=(new DateTimeImmutable('today'))->modify('+'.$days.' days');
        $start=$date->setTime(10,0)->format('Y-m-d H:i:s');
        $end=$date->setTime(12,0)->format('Y-m-d H:i:s');
        $deadline=$date->setTime(9,0)->format('Y-m-d H:i:s');
        execute('INSERT INTO events(club_id,created_by,title,description,venue,category_id,venue_id,starts_at,ends_at,registration_deadline,capacity,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$clubId,$creator,$title,$desc,$venue,$category['id'],$v['id'],$start,$end,$deadline,min(70,$v['capacity']),$status]);
    }
    foreach([[$sports['id'],$leader['id'],'Welcome to Sports Club','Join our fictional sample sports activities and see upcoming events.'],[$science['id'],$admin['id'],'Research group introduction','This sample announcement introduces our academic discussion sessions.']] as [$club,$author,$title,$body]) {
        if (!row('SELECT id FROM announcements WHERE club_id=? AND title=?',[$club,$title])) execute('INSERT INTO announcements(club_id,posted_by,title,body) VALUES(?,?,?,?)',[$club,$author,$title,$body]);
    }
    $pdo->commit();
    echo "Extra demo data added (or already present). Existing user accounts and passwords unchanged.\n";
    echo "Additional admin emails: demo.admin2@example.test through demo.admin5@example.test\nOther accounts: demo.sports@example.test, demo.student3@example.test, demo.student4@example.test\n";
    echo "These additional accounts use the password supplied to this command when they were first created.\n";
} catch(Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'Demo data setup failed: '.$e->getMessage()."\n");exit(1);
}
