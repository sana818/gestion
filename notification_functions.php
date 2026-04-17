<?php
function createNotification($db, $destinataire_id, $type, $message, $lien = null) {
    $stmt = $db->prepare("
        INSERT INTO notifications (destinataire_id, type, message, date, lu, lien) 
        VALUES (?, ?, ?, NOW(), 0, ?)
    ");
    $stmt->execute([$destinataire_id, $type, $message, $lien]);
    return $db->lastInsertId();
}

function notifyDirectorAboutNewLeave($db, $employeName, $typeConge, $joursDemandes, $dateDebut, $dateFin) {
    // Trouver tous les directeurs
    $directorQuery = $db->prepare("
        SELECT id FROM registre 
        WHERE role IN ('directeur', 'admin') 
        ORDER BY FIELD(role, 'directeur', 'admin') 
        LIMIT 1
    ");
    $directorQuery->execute();
    $director = $directorQuery->fetch();
    
    if ($director) {
        $directorId = (int)$director['id'];
        $message = "Nouvelle demande de congé de $employeName - Type: " .
            ucfirst($typeConge) . " - $joursDemandes jour(s) du " .
            date('d/m/Y', strtotime($dateDebut)) . " au " . date('d/m/Y', strtotime($dateFin));
        
        return createNotification($db, $directorId, 'nouvelle_demande', $message, '/admin/conges');
    }
    return null;
}

function notifyEmployeeAboutLeaveStatus($db, $employeId, $statut, $commentaire = '') {
    $statutText = $statut === 'accepte' ? 'approuvée' : 'refusée';
    $message = "Votre demande de congé a été $statutText";
    if ($commentaire) {
        $message .= " - Commentaire: $commentaire";
    }
    
    return createNotification($db, $employeId, 'statut_conge', $message, '/mes-conges');
}

// Fonction pour notifier l'employé (compatible avec votre code existant)
function notifierEmploye($db, $employe_id, $demande_id, $decision, $dates) {
    $statut = $decision == 'accepte' ? 'acceptée' : 'refusée';
    $date_debut = date('d/m/Y', strtotime($dates['debut']));
    $date_fin = date('d/m/Y', strtotime($dates['fin']));
    $message = "Votre demande de congé du $date_debut au $date_fin a été $statut";
    
    return createNotification($db, $employe_id, 'statut_conge', $message, '/mes-conges');
}

// Fonction pour notifier les directeurs (compatible avec votre code existant)
function notifierDirecteurs($db, $employe_id, $demande_id, $employe_nom, $dates) {
    $date_debut = date('d/m/Y', strtotime($dates['debut']));
    $date_fin = date('d/m/Y', strtotime($dates['fin']));
    $message = "Nouvelle demande de congé (ID: $demande_id) de l'employé $employe_nom du $date_debut au $date_fin";
    
    // Récupérer tous les directeurs
    $stmt = $db->prepare("SELECT id FROM registre WHERE role IN ('directeur', 'admin')");
    $stmt->execute();
    $directeurs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $results = [];
    foreach ($directeurs as $directeur_id) {
        $results[] = createNotification($db, $directeur_id, 'nouvelle_demande', $message, '/admin/conges');
    }
    
    return $results;
}
?>