<?php
namespace App\Core;
final class Reports {
    public static function eventRows(?int $clubId=null): array {
        $where=$clubId===null?'':' WHERE e.club_id=?';
        return \rows("SELECT e.id,e.title,e.status,e.starts_at,e.capacity,e.venue,c.name AS club_name,
            (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status='registered') AS registered,
            (SELECT COUNT(*) FROM attendance a JOIN event_registrations r ON r.id=a.registration_id
                WHERE r.event_id=e.id AND r.status='registered' AND a.is_present=1) AS attended,
            (SELECT ROUND(AVG(f.rating),2) FROM event_feedback f WHERE f.event_id=e.id) AS avg_rating
            FROM events e JOIN clubs c ON c.id=e.club_id".$where." ORDER BY e.starts_at DESC", $clubId===null?[]:[$clubId]);
    }
    public static function membershipRows(?int $clubId=null): array {
        $where=$clubId===null?'':' WHERE c.id=?';
        return \rows("SELECT c.name AS club_name,
            (SELECT COUNT(*) FROM club_memberships m WHERE m.club_id=c.id AND m.status='approved') AS members,
            (SELECT COUNT(*) FROM club_memberships m WHERE m.club_id=c.id AND m.status='pending') AS pending
            FROM clubs c".$where." ORDER BY c.name", $clubId===null?[]:[$clubId]);
    }
}
