document.addEventListener('DOMContentLoaded', () => {
    const token = localStorage.getItem('jwt');
    if (!token) {
        window.location.href = 'connexion.html';
        return;
    }

    // Déclaration des fonctions globales

    window.getUsers = function() {
        fetch('read.php', {
            method: 'GET',
            headers: { 
                'Authorization': 'Bearer ' + token,
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('Erreur réseau');
            return res.json();
        })
        .then(data => {
            if (data.success) {
                renderUsers(data.data);
            } else {
                throw new Error(data.error || 'Erreur inconnue');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showError(error.message);
        });
    };

    window.createUser = function(nom, email, mot_de_passe) {
        fetch('create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ nom, email, mot_de_passe })
        })
        .then(handleResponse)
        .then(() => getUsers())
        .catch(showError);
    };

    window.updateUser = function(id, nom, email) {
        fetch('update.php', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ id, nom, email })
        })
        .then(handleResponse)
        .then(() => getUsers())
        .catch(showError);
    };

    window.supprimerUser = async function(id) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')) return;

        try {
            const response = await fetch(`delete.php?id=${id}`, {
                method: 'DELETE',
                headers: {
                    'Authorization': 'Bearer ' + localStorage.getItem('jwt'),
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Erreur lors de la suppression');
            }

            await getUsers();
            alert('Utilisateur supprimé avec succès');
        } catch (error) {
            alert('Erreur : ' + error.message);
        }
    };

    // Fonctions utilitaires

    function handleResponse(response) {
        if (!response.ok) {
            return response.json().then(err => { 
                throw new Error(err.error || 'Erreur serveur'); 
            });
        }
        return response.json();
    }

    function showError(message) {
        console.error('Erreur:', message);
        alert('Erreur: ' + message);
    }

    // Initialisation de la page
    getUsers();
});
