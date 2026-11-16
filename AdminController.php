<?php
namespace App\Controllers;
class AdminController {
    public function index(): void {
        require_role('admin');
        $stats=['users'=>scalar('SELECT COUNT(*) FROM users'),'clubs'=>scalar("SELECT COUNT(*) FROM clubs WHERE status='approved'"),'events'=>scalar("SELECT COUNT(*) FROM events WHERE status='approved'"),'pending'=>scalar("SELECT COUNT(*) FROM events WHERE status='pending'")];
        $recent=rows('SELECT action,entity_type,details,created_at FROM activity_logs ORDER BY id DESC LIMIT 12');
        $upcoming=rows("SELECT e.title,e.starts_at,c.name AS club_name FROM events e JOIN clubs c ON c.id=e.club_id WHERE e.status='approved' AND e.starts_at>=NOW() ORDER BY e.starts_at LIMIT 5");
        render('admin',compact('stats','recent','upcoming')+['pageTitle'=>'Administration overview']);
    }
    public function events(): void {
        require_role('admin');$status=(string)($_GET['status']??'pending');
        if(!in_array($status,['pending','approved','rejected','cancelled','completed','all'],true))$status='pending';
        $events=rows('SELECT e.*,c.name AS club_name,u.full_name AS organizer,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status=\'registered\') AS booked FROM events e JOIN clubs c ON c.id=e.club_id JOIN users u ON u.id=e.created_by '.($status==='all'?'':'WHERE e.status=?').' ORDER BY e.created_at DESC LIMIT 100',$status==='all'?[]:[$status]);
        render('admin_events',compact('events','status')+['pageTitle'=>'Review events']);
    }
    public function eventNew(): void {
        require_role('admin');
        $clubs=rows("SELECT id,name FROM clubs WHERE status='approved' ORDER BY name");
        render('admin_event_new',compact('clubs')+['pageTitle'=>'Add event']);
    }
    public function eventStatus(): void {
        require_role('admin');
        $id=valid_int($_POST['event_id']??null);
        $status=post_str('status'); $note=post_str('review_note');
        if (!in_array($status,['approved','rejected','cancelled','completed'],true) || text_length($note)>1000) {
            flash('danger','Invalid review action or note.'); go('admin/events');
        }
        try {
            transaction(function () use ($id,$status,$note): void {
                $event=row('SELECT e.*,c.status AS club_status FROM events e JOIN clubs c ON c.id=e.club_id WHERE e.id=? FOR UPDATE',[$id]);
                if (!$event) throw new \DomainException('Event not found.');
                if (in_array($status,['approved','rejected'],true) && $event['status']!=='pending') {
                    throw new \DomainException('Only pending proposals can be reviewed.');
                }
                if ($status==='approved') {
                    // Serialize approvals for a venue before checking its booking schedule.
                    $venue=row('SELECT capacity FROM venues WHERE id=? FOR UPDATE',[$event['venue_id']]);
                    if ($event['club_status']!=='approved' || !is_future($event['starts_at'])
                        || strtotime($event['ends_at'])<=strtotime($event['starts_at'])
                        || ($event['registration_deadline'] && (!is_future($event['registration_deadline']) || strtotime($event['registration_deadline'])>strtotime($event['starts_at'])))
                        || !$event['category_id'] || !$venue || (int)$event['capacity']<1
                        || (int)$event['capacity']>(int)$venue['capacity']) {
                        throw new \DomainException('Approve only future events with an active club, valid dates/deadline, category, venue and capacity.');
                    }
                    $conflict=row("SELECT id FROM events WHERE venue_id=? AND status='approved' AND id<>? AND starts_at<? AND ends_at>? LIMIT 1 FOR UPDATE",[$event['venue_id'],$id,$event['ends_at'],$event['starts_at']]);
                    if ($conflict) throw new \DomainException('This venue is already booked at the proposed time.');
                }
                if ($status==='completed' && ($event['status']!=='approved' || is_future($event['ends_at']))) {
                    throw new \DomainException('Only approved events that have ended can be completed.');
                }
                if ($status==='cancelled' && !in_array($event['status'],['pending','approved'],true)) {
                    throw new \DomainException('This event cannot be cancelled.');
                }
                $savedNote=in_array($status,['approved','rejected'],true)?($note?:null):$event['review_note'];
                execute('UPDATE events SET status=?,review_note=? WHERE id=?',[$status,$savedNote,$id]);
                notify((int)$event['created_by'],'Event '.$status,'Your event '.$event['title'].' was marked '.$status.'.','event&id='.$id);
                if ($status==='cancelled') foreach (rows("SELECT user_id FROM event_registrations WHERE event_id=? AND status='registered'",[$id]) as $r) {
                    notify((int)$r['user_id'],'Event cancelled',$event['title'].' has been cancelled.','event&id='.$id);
                }
                audit('event_'.$status,'event',$id,$note);
            });
            flash('success','Event marked '.$status.'.');
        } catch (\DomainException $error) {
            flash('warning',$error->getMessage());
        } catch (\Throwable $error) {
            error_log('[event review] '.$error); flash('danger','The event could not be updated. Please try again.');
        }
        go('admin/events');
    }
    public function clubs(): void {
        require_role('admin');$clubs=rows('SELECT c.*,u.full_name AS leader_name,(SELECT COUNT(*) FROM club_memberships m WHERE m.club_id=c.id AND m.status=\'approved\') AS members FROM clubs c LEFT JOIN users u ON u.id=c.leader_user_id ORDER BY c.name');
        render('admin_clubs',compact('clubs')+['pageTitle'=>'Manage clubs']);
    }
    public function clubForm(): void {
        require_role('admin');$id=valid_int($_GET['id']??null);
        $club=$id?row('SELECT * FROM clubs WHERE id=?',[$id]):null;
        if($id&&!$club) {flash('danger','Club not found.');go('admin/clubs');}
        $leaders=rows("SELECT id,full_name FROM users WHERE role='club_leader' AND status='active' ORDER BY full_name");
        render('admin_club_form',compact('club','leaders')+['pageTitle'=>$club?'Edit club':'Add new club']);
    }
    public function clubSave(): void {
        require_role('admin');$id=valid_int($_POST['id']??null);$name=post_str('name');$desc=post_str('description');$contact=post_str('contact_email');$category=post_str('category');$faculty=post_str('faculty');$leader=valid_int($_POST['leader_user_id']??null);
        if(text_length($name)<4||text_length($name)>150||text_length($desc)<20||text_length($desc)>10000||text_length($category)<3||text_length($category)>80||($contact!==''&&(!filter_var($contact,FILTER_VALIDATE_EMAIL)||text_length($contact)>190))||!in_array($faculty,faculty_options(),true)) {flash('danger','Check club name, description (20+ characters), category, and faculty.');go('admin/club/form',$id?['id'=>$id]:[]);}
        if($leader && !row("SELECT id FROM users WHERE id=? AND role='club_leader' AND status='active'",[$leader])) {flash('danger','Select an active club leader.');go('admin/club/form',$id?['id'=>$id]:[]);}
        if($id) {
            $club=row('SELECT * FROM clubs WHERE id=?',[$id]);if(!$club) {flash('danger','Club not found.');go('admin/clubs');}
            execute('UPDATE clubs SET name=?,description=?,contact_email=?,category=?,faculty=?,leader_user_id=? WHERE id=?',[$name,$desc,$contact?:null,$category,$faculty,$leader?:null,$id]);
        } else {
            $slug=slugify($name);$root=$slug;$i=2;
            while(row('SELECT id FROM clubs WHERE slug=?',[$slug]))$slug=$root.'-'.$i++;
            execute("INSERT INTO clubs(name,slug,description,contact_email,category,faculty,leader_user_id,status) VALUES(?,?,?,?,?,?,?,'approved')",[$name,$slug,$desc,$contact?:null,$category,$faculty,$leader?:null]);
            $id=(int)db()->lastInsertId();
        }
        if($leader) notify($leader,'Club assignment','You are assigned to manage '.$name.'.','leader');
        audit('club_saved','club',$id);flash('success','Club saved successfully.');go('admin/clubs');
    }
    public function clubStatus(): void {
        require_role('admin');$id=valid_int($_POST['club_id']??null);$status=post_str('status');
        if(!in_array($status,['approved','suspended'],true)) {flash('danger','Invalid status.');go('admin/clubs');}
        execute('UPDATE clubs SET status=? WHERE id=?',[$status,$id]);audit('club_'.$status,'club',$id);flash('success','Club status updated.');go('admin/clubs');
    }
    public function users(): void {
        require_role('admin');$search=text_slice(trim((string)($_GET['q']??'')),0,80);
        $params=[];$where='';if($search!==''){$where=' WHERE full_name LIKE ? OR email LIKE ? OR student_number LIKE ?';$term='%'.clean_like($search).'%';$params=[$term,$term,$term];}
        $users=rows('SELECT id,full_name,email,student_number,faculty,role,status,created_at FROM users'.$where.' ORDER BY created_at DESC LIMIT 200',$params);
        render('admin_users',compact('users','search')+['pageTitle'=>'Manage accounts']);
    }
    public function adminCreate(): void {
        require_role('admin');
        render('admin_create', ['pageTitle'=>'Create administrator']);
    }
    public function adminSave(): void {
        require_role('admin');
        $name=post_str('full_name');
        $email=strtolower(post_str('email'));
        $studentNumber=post_str('student_number');
        $faculty=post_str('faculty');
        $password=(string)($_POST['password']??'');
        if (text_length($name)<3 || text_length($name)>150 || !filter_var($email,FILTER_VALIDATE_EMAIL)
            || text_length($email)>190 || text_length($studentNumber)>50
            || !in_array($faculty,faculty_options(),true) || strlen($password)<10 || strlen($password)>72) {
            flash('danger','Enter a valid name, email, faculty and password of 10–72 characters.');go('admin/create');
        }
        try {
            if (row('SELECT id FROM users WHERE email=? OR (student_number IS NOT NULL AND student_number=?) LIMIT 1',[$email,$studentNumber])) {
                flash('warning','That email address or student number is already registered. Use Change role in Users instead.');go('admin/create');
            }
            execute("INSERT INTO users(full_name,email,student_number,faculty,role,password_hash,status) VALUES(?,?,?,?,'admin',?,'active')",
                [$name,$email,$studentNumber?:null,$faculty,password_hash($password,PASSWORD_DEFAULT)]);
            $id=(int)db()->lastInsertId();
            audit('admin_created','user',$id);
            flash('success','Administrator created. They can now sign in with the new email and password.');go('admin/users');
        } catch (\PDOException $error) {
            flash('danger','Unable to add administrator. Check for duplicate account details.');go('admin/create');
        }
    }
    public function memberRemove(): void {
        require_role('admin');
        $clubId=valid_int($_POST['club_id']??null);
        $membershipId=valid_int($_POST['membership_id']??null);
        try {
            transaction(function () use ($clubId,$membershipId): void {
                $membership=row("SELECT m.id,m.user_id,c.name AS club_name FROM club_memberships m JOIN clubs c ON c.id=m.club_id WHERE m.id=? AND m.club_id=? AND m.status='approved' FOR UPDATE",[$membershipId,$clubId]);
                if (!$membership) throw new \DomainException('This person is not an approved member of that club.');
                execute("UPDATE club_memberships SET status='left' WHERE id=? AND club_id=? AND status='approved'",[$membershipId,$clubId]);
                notify((int)$membership['user_id'],'Club membership updated','Your membership in '.$membership['club_name'].' was removed by an administrator.','dashboard');
                audit('club_member_removed','club',$clubId,'Membership '.$membershipId);
            });
            flash('success','Member removed from the club. Their account is unchanged.');
        } catch (\DomainException $e) {flash('warning',$e->getMessage());}
        go('leader/members',['club_id'=>$clubId]);
    }
    public function userRole(): void {
        $me=require_role('admin');$id=valid_int($_POST['user_id']??null);$role=post_str('role');
        if($id===(int)$me['id'] || !in_array($role,['student','club_leader','admin'],true)) {flash('danger','You cannot change this role.');go('admin/users');}
        $target=row('SELECT id,role FROM users WHERE id=?',[$id]);if(!$target) {flash('danger','Account not found.');go('admin/users');}
        if($target['role']==='admin' && $role!=='admin' && (int)scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")<=1) {flash('warning','At least one active administrator must remain.');go('admin/users');}
        if($role!=='club_leader' && scalar('SELECT COUNT(*) FROM clubs WHERE leader_user_id=?',[$id])>0) {flash('warning','Reassign this user’s clubs before changing their role.');go('admin/users');}
        execute('UPDATE users SET role=? WHERE id=?',[$role,$id]);notify($id,'Account role updated','Your platform role is now '.str_replace('_',' ',$role).'.','dashboard');
        audit('user_role_changed','user',$id,$role);flash('success','User role changed.');go('admin/users');
    }
    public function userStatus(): void {
        $me=require_role('admin');$id=valid_int($_POST['user_id']??null);$status=post_str('status');
        if($id===(int)$me['id']||!in_array($status,['active','suspended'],true)) {flash('danger','Invalid status change.');go('admin/users');}
        if($status==='suspended' && (int)scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' AND id=?",[$id])>0 && (int)scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")<=1) {flash('warning','At least one active administrator must remain.');go('admin/users');}
        if($status==='suspended' && scalar('SELECT COUNT(*) FROM clubs WHERE leader_user_id=?',[$id])>0) {flash('warning','Reassign the user’s managed clubs before suspending them.');go('admin/users');}
        execute('UPDATE users SET status=? WHERE id=?',[$status,$id]);audit('user_'.$status,'user',$id);flash('success','Account status changed.');go('admin/users');
    }
    public function export(): void {
        require_role('admin');
        $records=rows("SELECT e.title,c.name AS club,e.starts_at,e.venue,e.status,e.capacity,COUNT(CASE WHEN r.status='registered' THEN 1 END) AS registrations,COUNT(CASE WHEN r.status='registered' AND r.attended_at IS NOT NULL THEN 1 END) AS attended FROM events e JOIN clubs c ON c.id=e.club_id LEFT JOIN event_registrations r ON r.event_id=e.id GROUP BY e.id,e.title,c.name,e.starts_at,e.venue,e.status,e.capacity ORDER BY e.starts_at DESC");
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="campus-connect-events-'.date('Ymd').'.csv"');
        $out=fopen('php://output','w');fputcsv($out,['Event','Club','Date & Time','Venue','Status','Capacity','Registered','Attended']);
        foreach($records as $rec){$safe=[];foreach(array_values($rec) as $val){$val=(string)$val;$safe[]=preg_match('/^[=+@\-\t\r]/u',$val)?"'".$val:$val;}fputcsv($out,$safe);}
        fclose($out);exit;
    }
    public function records(): void {
        require_role('admin');
        $categories=rows('SELECT * FROM event_categories ORDER BY name');
        $venues=rows('SELECT * FROM venues ORDER BY name');
        render('admin_records',compact('categories','venues')+['pageTitle'=>'Categories & venues']);
    }
    public function categorySave(): void {
        require_role('admin');$id=valid_int($_POST['id']??null);
        $name=post_str('name');$description=post_str('description');
        if(text_length($name)<3||text_length($name)>100||text_length($description)>255){flash('danger','Check category name and description.');go('admin/records');}
        try{
            if($id){execute('UPDATE event_categories SET name=?,description=? WHERE id=?',[$name,$description,$id]);}
            else {execute('INSERT INTO event_categories(name,description) VALUES(?,?)',[$name,$description]);}
            flash('success','Event category saved.');
        }catch(\PDOException $e){flash('danger','Category could not be saved. The name may already exist.');}
        go('admin/records');
    }
    public function venueSave(): void {
        require_role('admin'); $id=valid_int($_POST['id']??null);
        $name=post_str('name'); $location=post_str('location'); $capacity=valid_int($_POST['capacity']??null);
        if (text_length($name)<3 || text_length($name)>180 || text_length($location)>180 || $capacity<1 || $capacity>100000) {
            flash('danger','Enter a venue name and capacity from 1 to 100,000.'); go('admin/records');
        }
        try {
            transaction(function () use ($id,$name,$location,$capacity): void {
                if ($id) {
                    $venue=row('SELECT id FROM venues WHERE id=? FOR UPDATE',[$id]);
                    if (!$venue) throw new \DomainException('Venue not found.');
                    $largest=scalar("SELECT COALESCE(MAX(capacity),0) FROM events WHERE venue_id=? AND status IN ('pending','approved')",[$id]);
                    if ($largest>$capacity) throw new \DomainException('This capacity is below that of an existing event.');
                    execute('UPDATE venues SET name=?,location=?,capacity=? WHERE id=?',[$name,$location,$capacity,$id]);
                    execute('UPDATE events SET venue=? WHERE venue_id=?',[$name,$id]);
                } else {
                    execute('INSERT INTO venues(name,location,capacity) VALUES(?,?,?)',[$name,$location,$capacity]);
                }
                audit('venue_saved','venue',$id?:((int)db()->lastInsertId()));
            });
            flash('success','Venue saved.');
        } catch (\DomainException $error) {
            flash('danger',$error->getMessage());
        } catch (\PDOException $error) {
            error_log('[venue save] '.$error); flash('danger','Venue could not be saved. The name may already exist; try again.');
        }
        go('admin/records');
    }
    public function reports(): void {
        require_role('admin');
        $events=\App\Core\Reports::eventRows();
        $memberships=\App\Core\Reports::membershipRows();
        $reportClub=null;
        render('reports',compact('events','memberships','reportClub')+['pageTitle'=>'University participation reports']);
    }
}
