<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque, pour pouvoir la relire et
 * la refaire. Le dessin est fait en 1024 puis réduit en 256, ce qui donne les
 * bords lissés que GD ne produit pas sur un remplissage direct.
 *
 * Une page de calendrier, avec ses anneaux et sa grille de jours, et une
 * horloge posée dessus : « ce qui va se passer, et à quelle heure ». La palette
 * est celle des plugins frères — même gris, même jaune — pour qu'on voie dans
 * le menu qu'ils vont ensemble.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$jaune      = imagecolorallocate($grand, 0xF5, 0xB3, 0x00);
$gris       = imagecolorallocate($grand, 0x5B, 0x6B, 0x73);
$grisSombre = imagecolorallocate($grand, 0x36, 0x44, 0x4B);
$grisClair  = imagecolorallocate($grand, 0xA5, 0xB5, 0xBC);
$blanc      = imagecolorallocate($grand, 0xF4, 0xF6, 0xF7);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* La page, puis son bandeau. */
imagefilledrectangle($grand, $e(24), $e(40), $e(212), $e(224), $blanc);
imagefilledrectangle($grand, $e(24), $e(40), $e(212), $e(84), $gris);
imagesetthickness($grand, $e(6));
imagerectangle($grand, $e(24), $e(40), $e(212), $e(224), $gris);

/* Les anneaux, qui font lire « calendrier » et non « tableau ». */
foreach (array(64, 118, 172) as $x) {
    imagefilledrectangle($grand, $e($x - 6), $e(24), $e($x + 6), $e(58), $grisSombre);
}

/* La grille des jours ; l'un d'eux en jaune, celui où quelque chose partira. */
for ($ligne = 0; $ligne < 3; $ligne++) {
    for ($colonne = 0; $colonne < 5; $colonne++) {
        $x = 40 + $colonne * 34;
        $y = 100 + $ligne * 38;
        $couleur = ($ligne == 1 && $colonne == 1) ? $jaune : $grisClair;
        imagefilledrectangle($grand, $e($x), $e($y), $e($x + 22), $e($y + 24), $couleur);
    }
}

/* L'horloge, en bas à droite, par-dessus la page. */
imagefilledellipse($grand, $e(188), $e(188), $e(116), $e(116), $grisSombre);
imagefilledellipse($grand, $e(188), $e(188), $e(96), $e(96), $jaune);
imagesetthickness($grand, $e(9));
imageline($grand, $e(188), $e(188), $e(188), $e(152), $grisSombre);
imageline($grand, $e(188), $e(188), $e(214), $e(200), $grisSombre);
imagefilledellipse($grand, $e(188), $e(188), $e(14), $e(14), $grisSombre);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);
imagepng($icone, __DIR__ . '/../plugin_info/calendrierbe_icon.png');
echo "plugin_info/calendrierbe_icon.png\n";
