<?php
namespace App\Controllers;
class LeaderController {
    private function manager(int $clubId): array {
        $me=require_role('club_leader','admin');
        $club=row('SELECT * FROM clubs WHERE id=?',[$clubId]);
        if (!$club || ($me['role']!=='admin' && (int)$club['leader_user_id']!==(int)$me['id'])) {
            http_response_code(403);render('error',['pageTitle'=>'Access denied','errorMessage'=>'You can manage only your assigned club.']);exit;
        }
        return $club;
    }
    public function index(): void {
        $me=require_role('club_leader','admin');
        $clubs=$me['role']==='admin' ? rows('SELECT * FROM clubs ORDER BY name') : rows('SELECT * FROM clubs WHERE leader_user_id=? ORDER BY name',[$me['id']]);
        $clubId=valid_int($_GET['club_id']??null) ?: (int)($clubs[0]['id']??0);
        if(!$clubId) { render('leader',['pageTitle'=>'Club management','clubs'=>$clubs,'club'=>null,'events'=>[],'pending'=>[],'notices'=>[]]);return; }
        $club=$this->manager($clubId);
        $events=rows("SELECT e.*,(SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status='registered') AS booked FROM events e WHERE e.club_id=? ORDER BY e.starts_at DESC LIMIT 30",[$clubId]);
        $pending=rows("SELECT m.id,m.status,m.joined_at,u.full_name,u.student_number,u.faculty FROM club_memberships m JOIN users u ON u.id=m.user_id WHERE m.club_id=? AND m.status='pending' ORDER BY m.joined_at",[$clubId]);
        $notices=rows('SELECT * FROM announcements WHERE club_id=? ORDER BY created_at DESC LIMIT 6',[$clubId]);
        render('leader',compact('clubs','club','events','pending','notices')+['pageTitle'=>'Club management']);
    }
    public function members(): void {
        $clubId=valid_int($_GET['club_id']??null);$club=$this->manager($clubId);
        $members=rows("SELECT m.*,u.full_name,u.email,u.student_number,u.faculty FROM club_memberships m JOIN users u ON u.id=m.user_id WHERE m.club_id=? ORDER BY FIELD(m.status,'pending','approved','rejected','left'),u.full_name",[$clubId]);
        render('members',compact('club','members')+['pageTitle'=>'Club members']);
    }
    public function membership(): void {
        $clubId=valid_int($_POST['club_id']??null);$club=$this->manager($clubId);
        $memberId=valid_int($_POST['membership_id']??null);$status=post_str('status');
        if(!in_array($status,['approved','rejected'],true)) { flash('danger','Invalid membership action.');go('leader/members',['club_id'=>$clubId]); }
        $member=row("SELECT * FROM club_memberships WHERE id=? AND club_id=? AND status='pending'",[$memberId,$clubId]);
        if(!$member) { flash('warning','Membership request is no longer pending.');go('leader/members',['club_id'=>$clubId]); }
        if (!execute("UPDATE club_memberships SET status=? WHERE id=? AND status='pending'",[$status,$memberId])) { flash('warning','This request was already processed.');go('leader/members',['club_id'=>$clubId]); }
        notify((int)$member['user_id'],'Club membership '.($status==='approved'?'approved':'update'), 'Your request to join '.$club['name'].' was '.$status.'.', 'club&id='.$clubId);
        audit('membership_'.$status,'club',$clubId);flash('success','Membership request updated.');go('leader/members',['club_id'=>$clubId]);
    }
    public function eventForm(): void {
        $clubId=valid_int($_GET['club_id']??null);$club=$this->manager($clubId);
        $event=null;$id=valid_int($_GET['id']??null);
        if($id){
            $event=row('SELECT * FROM events WHERE id=? AND club_id=?',[$id,$clubId]);
            if(!$event || !in_array($event['status'],['pending','rejected'],true)){
                flash('warning','Only pending or rejected event proposals can be edited.');go('leader',['club_id'=>$clubId]);
            }
        }
        $categories=rows('SELECT id,name FROM event_categories ORDER BY name');
        $venues=rows('SELECT id,name,location,capacity FROM venues ORDER BY name');
        render('event_form',compact('club','event','categories','venues')+['pageTitle'=>$event?'Edit event proposal':'Create event proposal']);
    }
    public function eventSave(): void {
        $clubId=valid_int($_POST['club_id']??null); $club=$this->manager($clubId);
        $id=valid_int($_POST['id']??null); $back=['club_id'=>$clubId]; if ($id) $back['id']=$id;
        $title=post_str('title'); $description=post_str('description');
        $categoryId=valid_int($_POST['category_id']??null); $venueId=valid_int($_POST['venue_id']??null);
        $starts=post_str('starts_at'); $ends=post_str('ends_at'); $deadline=post_str('registration_deadline');
        $capacity=valid_int($_POST['capacity']??null);
        old_store(array_intersect_key($_POST,array_flip(['club_id','id','title','description','category_id','venue_id','starts_at','ends_at','registration_deadline','capacity'])));
        $startTime=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$starts);
        $endTime=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$ends);
        $deadlineTime=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$deadline);
        $dates=$startTime&&$endTime&&$deadlineTime&&$startTime->format('Y-m-d\TH:i')===$starts&&$endTime->format('Y-m-d\TH:i')===$ends&&$deadlineTime->format('Y-m-d\TH:i')===$deadline;
        if (text_length($title)<5 || text_length($title)>180 || text_length($description)<20 || text_length($description)>10000
            || !$dates || $capacity<1 || $capacity>100000 || $endTime<=$startTime
            || $startTime->getTimestamp()<=time() || $deadlineTime>$startTime || $deadlineTime->getTimestamp()<=time()) {
            flash('danger','Check the title, description, capacity and future event dates/deadline.'); go('leader/event/form',$back);
        }
        try {
            transaction(function () use (&$id,$clubId,$title,$description,$categoryId,$venueId,$capacity,$startTime,$endTime,$deadlineTime): void {
                $club=row('SELECT status FROM clubs WHERE id=? FOR UPDATE',[$clubId]);
                if (!$club || $club['status']!=='approved') throw new \DomainException('Inactive clubs cannot submit events.');
                if ($id) {
                    $existing=row('SELECT id,status FROM events WHERE id=? AND club_id=? FOR UPDATE',[$id,$clubId]);
                    if (!$existing || !in_array($existing['status'],['pending','rejected'],true)) throw new \DomainException('Only pending or rejected event proposals can be edited.');
                }
                $venue=row('SELECT id,name,capacity FROM venues WHERE id=? FOR UPDATE',[$venueId]);
                $category=row('SELECT id FROM event_categories WHERE id=?',[$categoryId]);
                if (!$category || !$venue || $capacity>(int)$venue['capacity']) throw new \DomainException('Choose a valid category and venue, with capacity within the venue limit.');
                $values=[$title,$description,$categoryId,$venueId,$venue['name'],$startTime->format('Y-m-d H:i:s'),$endTime->format('Y-m-d H:i:s'),$deadlineTime->format('Y-m-d H:i:s'),$capacity];
                if ($id) {
                    execute("UPDATE events SET title=?,description=?,category_id=?,venue_id=?,venue=?,starts_at=?,ends_at=?,registration_deadline=?,capacity=?,status='pending',review_note=NULL WHERE id=? AND club_id=?",array_merge($values,[$id,$clubId]));
                } else {
                    execute("INSERT INTO events(title,description,category_id,venue_id,venue,starts_at,ends_at,registration_deadline,capacity,club_id,created_by,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')",array_merge($values,[$clubId,require_login()['id']]));
                    $id=(int)db()->lastInsertId();
                }
                foreach (rows("SELECT id FROM users WHERE role='admin' AND status='active'") as $admin) {
                    notify((int)$admin['id'],'Event awaiting approval',$title.' needs review.','admin/events');
                }
                audit('event_submitted','event',$id);
            });
            unset($_SESSION['old']);
            flash('success','Event proposal submitted for approval.'); go('leader',['club_id'=>$clubId]);
        } catch (\DomainException $error) {
            flash('danger',$error->getMessage());
        } catch (\Throwable $error) {
            error_log('[event submission] '.$error); flash('danger','The event could not be saved. Please try again.');
        }
        go('leader/event/form',$back);
    }
    public function eventCancel(): void {
        $clubId=valid_int($_POST['club_id']??null);$this->manager($clubId);$id=valid_int($_POST['event_id']??null);
        $event=row('SELECT title,status FROM events WHERE id=? AND club_id=?',[$id,$clubId]);
        if($event && in_array($event['status'],['pending','approved'],true)) {
            execute("UPDATE events SET status='cancelled' WHERE id=? AND club_id=?",[$id,$clubId]);
            foreach(rows("SELECT user_id FROM event_registrations WHERE event_id=? AND status='registered'",[$id]) as $r) notify((int)$r['user_id'],'Event cancelled',$event['title'].' has been cancelled.','dashboard');
            audit('event_cancelled','event',$id);flash('success','Event cancelled and participants notified.');
        }
        go('leader',['club_id'=>$clubId]);
    }
    public function attendees(): void {
        $clubId=valid_int($_GET['club_id']??null);$this->manager($clubId);$id=valid_int($_GET['event_id']??null);
        $event=row('SELECT * FROM events WHERE id=? AND club_id=?',[$id,$clubId]);if(!$event) { flash('danger','Event not found.');go('leader',['club_id'=>$clubId]); }
        $registrations=rows("SELECT r.*,a.is_present,a.marked_at,u.full_name,u.student_number,u.faculty,u.email FROM event_registrations r JOIN users u ON u.id=r.user_id LEFT JOIN attendance a ON a.registration_id=r.id WHERE r.event_id=? ORDER BY FIELD(r.status,'registered','cancelled'),u.full_name",[$id]);
        render('attendees',compact('event','registrations','clubId')+['pageTitle'=>'Event attendance']);
    }
    public function attendance(): void {
        $clubId=valid_int($_POST['club_id']??null); $this->manager($clubId);
        $id=valid_int($_POST['event_id']??null); $rid=valid_int($_POST['registration_id']??null);
        $attend=post_str('attended');
        $message=transaction(function () use ($clubId,$id,$rid,$attend): string {
            $event=row('SELECT starts_at,status FROM events WHERE id=? AND club_id=? FOR UPDATE',[$id,$clubId]);
            if (!$event || !in_array($event['status'],['approved','completed'],true) || is_future($event['starts_at'])) {
                return 'Attendance can be recorded only after an approved event starts.';
            }
            $registration=row("SELECT id FROM event_registrations WHERE id=? AND event_id=? AND status='registered'",[$rid,$id]);
            if (!$registration) return 'Registration not found or cancelled.';
            if (!in_array($attend,['yes','no','clear'],true)) return 'Choose present, absent or clear.';
            if ($attend==='clear') {
                execute('DELETE FROM attendance WHERE registration_id=?',[$rid]);
                execute('UPDATE event_registrations SET attended_at=NULL WHERE id=?',[$rid]);
            } else {
                execute('INSERT INTO attendance(registration_id,is_present) VALUES(?,?) ON DUPLICATE KEY UPDATE is_present=VALUES(is_present),marked_at=NOW()',[$rid,$attend==='yes'?1:0]);
                execute('UPDATE event_registrations SET attended_at='.($attend==='yes'?'NOW()':'NULL').' WHERE id=?',[$rid]);
            }
            if ($attend!=='yes') execute('DELETE FROM event_feedback WHERE registration_id=? OR (event_id=? AND user_id=(SELECT user_id FROM event_registrations WHERE id=?))',[$rid,$id,$rid]);
            audit('attendance_updated','event',$id);
            return '';
        });
        flash($message?'warning':'success',$message?:'Attendance updated.');
        go('leader/attendees',['club_id'=>$clubId,'event_id'=>$id]);
    }
    public function announcement(): void {
        $clubId=valid_int($_POST['club_id']??null);$club=$this->manager($clubId);
        $title=post_str('title');$body=post_str('body');$id=valid_int($_POST['announcement_id']??null);
        if(text_length($title)<4||text_length($title)>180||text_length($body)<10||text_length($body)>5000){
            flash('danger','Announcement needs a title and a message of at least 10 characters.');go('leader',['club_id'=>$clubId]);
        }
        if($id){
            $item=row('SELECT id FROM announcements WHERE id=? AND club_id=?',[$id,$clubId]);
            if(!$item){flash('danger','Announcement not found.');go('leader',['club_id'=>$clubId]);}
            execute("UPDATE announcements SET title=?,body=?,status='published',updated_at=NOW() WHERE id=?",[$title,$body,$id]);
        }else{
            execute("INSERT INTO announcements(club_id,posted_by,title,body,status) VALUES(?,?,?,?,'published')",[$clubId,require_login()['id'],$title,$body]);
            foreach(rows("SELECT user_id FROM club_memberships WHERE club_id=? AND status='approved'",[$clubId]) as $m)
                notify((int)$m['user_id'],'New announcement: '.$club['name'],$title,'club&id='.$clubId);
        }
        audit('announcement_saved','club',$clubId);flash('success','Announcement published.');go('leader',['club_id'=>$clubId]);
    }
    public function announcementStatus(): void {
        $clubId=valid_int($_POST['club_id']??null);$this->manager($clubId);
        $id=valid_int($_POST['announcement_id']??null);$status=post_str('status');
        if(in_array($status,['published','hidden'],true)){
            execute('UPDATE announcements SET status=?,updated_at=NOW() WHERE id=? AND club_id=?',[$status,$id,$clubId]);
            flash('success','Announcement status updated.');
        }
        go('leader',['club_id'=>$clubId]);
    }
    public function eventComplete(): void {
        $clubId=valid_int($_POST['club_id']??null);$this->manager($clubId);
        $id=valid_int($_POST['event_id']??null);
        $event=row('SELECT id,status,ends_at FROM events WHERE id=? AND club_id=?',[$id,$clubId]);
        if(!$event||$event['status']!=='approved'||strtotime($event['ends_at'])>time()){
            flash('warning','Only approved events that have ended can be marked complete.');
        }else{
            execute("UPDATE events SET status='completed' WHERE id=?",[$id]);
            audit('event_completed','event',$id);flash('success','Event marked completed. Attendees may submit feedback.');
        }
        go('leader',['club_id'=>$clubId]);
    }
    public function reports(): void {
        $clubId=valid_int($_GET['club_id']??null);$club=$this->manager($clubId);
        $events=\App\Core\Reports::eventRows($clubId);
        $memberships=\App\Core\Reports::membershipRows($clubId);
        $reportClub=$club;
        render('reports',compact('events','memberships','reportClub')+['pageTitle'=>'Club participation report']);
    }
    public function clubForm(): void {
        $clubId=valid_int($_GET['club_id']??null);$club=$this->manager($clubId);
        render('leader_club_form',compact('club')+['pageTitle'=>'Edit club details']);
    }
    public function clubSave(): void {
        $clubId=valid_int($_POST['club_id']??null);$this->manager($clubId);$description=post_str('description');$contact=post_str('contact_email');
        if(text_length($description)<20||text_length($description)>10000||($contact!==''&&(!filter_var($contact,FILTER_VALIDATE_EMAIL)||text_length($contact)>190))) { flash('danger','Description must contain 20–10,000 characters.');go('leader/club/form',['club_id'=>$clubId]); }
        execute('UPDATE clubs SET description=?,contact_email=? WHERE id=?',[$description,$contact?:null,$clubId]);audit('club_updated','club',$clubId);flash('success','Club details updated.');go('leader',['club_id'=>$clubId]);
    }
}
