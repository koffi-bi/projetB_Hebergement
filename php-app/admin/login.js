// Basculer entre les onglets Mot de passe / Face ID 
function changerOnglet(onglet) {
  document.querySelectorAll('.onglet').forEach(o  => o.classList.remove('actif'));
  document.querySelectorAll('.panneau').forEach(p => p.classList.remove('actif'));
  document.getElementById('onglet-' + onglet).classList.add('actif');
  document.getElementById('panneau-' + onglet).classList.add('actif');
}