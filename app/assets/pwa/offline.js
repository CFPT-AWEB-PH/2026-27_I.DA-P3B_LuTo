// Page hors connexion : on recharge la page demandée dès que le réseau revient.
document.getElementById('retry').addEventListener('click', () => location.reload());
window.addEventListener('online', () => location.reload());
