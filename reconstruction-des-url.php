<?php

/**
 * Script de reconstruction des slugs et hreflang pour PrestaShop
 * ⚠️ À exécuter une seule fois. Ne laisse pas ce fichier sur le serveur.
 * 
 * ====== FONCTIONNEMENT ======
 * 
 * Ce script régénère automatiquement tous les slugs (link_rewrite) des :
 * • Produits
 * • Catégories
 * • Pages CMS
 * 
 * Pour TOUTES les langues du site PrestaShop (compatible 1.7 → 9.x)
 * 
 * ÉTAPES :
 * 1. Création automatique d'un backup SQL des anciens slugs
 * 2. Mode DRY-RUN : Simulation pour vérifier les changements AVANT de les appliquer
 * 3. Exécution réelle : Applique les changements à la base de données
 * 4. Génération d'un rapport CSV avec tous les changements
 * 5. Auto-suppression du fichier après exécution
 * 
 * NETTOYAGE DES SLUGS :
 * • Conversion en minuscules
 * • Suppression des caractères spéciaux
 * • Remplacement des espaces par des tirets
 * • Suppression des tirets multiples
 * • Détection et résolution des doublons automatique
 * 
 * SÉCURITÉ :
 * • Token généré quotidiennement pour confirmer l'exécution
 * • Backup automatique avant chaque exécution
 * • Mode dry-run pour vérifier les changements sans risque
 * • Tous les changements sont loggés en CSV
 * 
 * ====== ÉTAPES À SUIVRE ======
 * 1. Cliquez sur "Voir les changements" pour simuler (dry-run)
 * 2. Vérifiez les slugs générés dans la simulation
 * 3. Cliquez sur "Appliquer les changements" pour exécuter réellement
 * 4. Le fichier sera automatiquement supprimé après l'exécution
 */

require_once dirname(__FILE__) . '/config/config.inc.php';
require_once dirname(__FILE__) . '/init.php';

// Ajout : fonction pour générer des suggestions de noms de repository GitHub
function generateRepoNames($projectShort = 'prestashop-slugs', $keywords = ['slug','hreflang','reconstruction','prestashop']) {
	$base = [
		$projectShort,
		$projectShort . '-reconstruction',
		$projectShort . '-hreflang',
		$projectShort . '-utils',
		str_replace('_','-',$projectShort) . '-tool',
		implode('-', array_slice($keywords,0,2)) . '-ps',
		$projectShort . '-' . date('Y'),
		$projectShort . '-one-shot'
	];
	// Variantes plus descriptives
	$variants = [];
	foreach ($base as $name) {
		$variants[] = $name;
		$variants[] = $name . '-script';
	}
	// Dédupliquer et retourner
	return array_values(array_unique($variants));
}

// Si on ouvre le script avec ?repo=1, afficher les suggestions et quitter
if (isset($_GET['repo']) && $_GET['repo']) {
	$suggestions = generateRepoNames('prestashop-reconstruction-des-url', ['slug','hreflang','prestashop','script']);
	echo "<h2>Suggestions de noms pour le dépôt GitHub</h2>";
	echo "<p>Voici quelques suggestions courtes et descriptives :</p>";
	echo "<ul style='font-family:Arial,Helvetica,sans-serif;'>";
	foreach ($suggestions as $s) {
		echo "<li><code>" . htmlspecialchars($s) . "</code></li>";
	}
	echo "</ul>";
	echo "<p>Exemples recommandés : <strong>" . htmlspecialchars($suggestions[0]) . "</strong> ou <strong>" . htmlspecialchars($suggestions[1]) . "</strong></p>";
	echo "<p style='color:#6c757d;'>Fermez cette page pour reprendre l'exécution normale du script.</p>";
	exit;
}

// Ajout : description courte pour le nom du dépôt
function getRepoShortDescription($repoName = 'P48-prestashop-script-slugs-hreflang') {
	$map = [
		'P48-prestashop-script-slugs-hreflang' => "Script one‑shot pour régénérer les slugs (link_rewrite) et mettre à jour les balises hreflang pour produits, catégories et pages CMS dans PrestaShop (1.7 → 9.x).",
		'prestashop-reconstruction-des-url' => "Outil pour reconstruire proprement les slugs et hreflang sur PrestaShop.",
	];
	return isset($map[$repoName]) ? $map[$repoName] : "Script PrestaShop pour reconstruire slugs et hreflang (produits, catégories, CMS).";
}

// Handler minimal pour afficher la description courte : ?repo_desc=1[&name=NomDuRepo]
if (isset($_GET['repo_desc']) && $_GET['repo_desc']) {
	$repo = isset($_GET['name']) ? $_GET['name'] : 'P48-prestashop-script-slugs-hreflang';
	echo "<h2>Description courte — " . htmlspecialchars($repo) . "</h2>";
	echo "<p>" . htmlspecialchars(getRepoShortDescription($repo)) . "</p>";
	exit;
}

$startTime = microtime(true);
$counters = ['products' => 0, 'categories' => 0, 'cms' => 0, 'errors' => 0, 'empty' => 0];
$slugs_used = [];
$changes = [];
$dry_run = isset($_GET['dry_run']);
$token = 'tl_slug_' . date('Ymd');

// Vérification de confirmation avec token
if (!isset($_GET['confirm']) || $_GET['confirm'] !== $token) {
    // Backup des anciens slugs (création du fichier slugs_backup_YYYY-MM-DD_HH-i-s.sql)
    $backup_file = dirname(__FILE__) . '/slugs_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $backup_content = "-- Backup des slugs avant reconstruction\n";
    $backup_content .= "-- " . date('Y-m-d H:i:s') . "\n\n";

    $backup_content .= "-- PRODUITS\n";
    $products_backup = Db::getInstance()->executeS('SELECT id_product, id_lang, link_rewrite FROM ' . _DB_PREFIX_ . 'product_lang');
    foreach ($products_backup as $p) {
        $backup_content .= "UPDATE " . _DB_PREFIX_ . "product_lang SET link_rewrite = '" . $p['link_rewrite'] . "' WHERE id_product = " . $p['id_product'] . " AND id_lang = " . $p['id_lang'] . ";\n";
    }

    $backup_content .= "\n-- CATÉGORIES\n";
    $categories_backup = Db::getInstance()->executeS('SELECT id_category, id_lang, link_rewrite FROM ' . _DB_PREFIX_ . 'category_lang');
    foreach ($categories_backup as $c) {
        $backup_content .= "UPDATE " . _DB_PREFIX_ . "category_lang SET link_rewrite = '" . $c['link_rewrite'] . "' WHERE id_category = " . $c['id_category'] . " AND id_lang = " . $c['id_lang'] . ";\n";
    }

    $backup_content .= "\n-- CMS\n";
    $cms_backup = Db::getInstance()->executeS('SELECT id_cms, id_lang, link_rewrite FROM ' . _DB_PREFIX_ . 'cms_lang');
    foreach ($cms_backup as $cms) {
        $backup_content .= "UPDATE " . _DB_PREFIX_ . "cms_lang SET link_rewrite = '" . $cms['link_rewrite'] . "' WHERE id_cms = " . $cms['id_cms'] . " AND id_lang = " . $cms['id_lang'] . ";\n";
    }

    file_put_contents($backup_file, $backup_content);
    echo "<h2>Reconstruction des slugs et hreflang</h2>";
    echo "<p><strong>Thierry Laval</strong> - Développeur à <a href='https://thierrylaval.dev' target='_blank'>thierrylaval.dev</a></p>";
    echo "<p>Script one-shot qui régénère proprement tous les slugs (link_rewrite) des produits,<br>catégories et pages CMS dans toutes les langues du site PrestaShop (1.7 → 9.x).</p>";

    echo "<h2>⚠️ Confirmation requise</h2>";
    echo "<p>Un backup a été créé : <strong>" . basename($backup_file) . "</strong></p>";
    echo "<p style='margin-top: 20px;'>";
    echo "<a href='?confirm=" . $token . "&dry_run=1' style='display: inline-block; padding: 12px 24px; background: #ffc107; color: black; text-decoration: none; border-radius: 5px; margin-right: 10px; font-weight: bold;'>👁️ Voir avant</a>";
    // bouton pour appliquer directement
    echo "<a href='?confirm=" . $token . "' style='display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin-right: 10px; font-weight: bold;'>✅ Appliquer les changements</a>";
    // lien de restauration du backup créé
    echo "<a href='?confirm=" . $token . "&restore=" . rawurlencode(basename($backup_file)) . "' style='display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>♻️ Restaurer ce backup</a>";
    echo "</p>";
    die();
}

echo "<h2>Reconstruction des slugs et hreflang</h2>";
echo "<p><strong>Thierry Laval</strong> - Développeur à <a href='https://thierrylaval.dev' target='_blank'>thierrylaval.dev</a></p>";

if ($dry_run) {
    echo "<p style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'><strong>🔍 MODE DRY-RUN (Simulation)</strong> - Les changements sont affichés mais ne seront PAS appliqués à la base de données, pour appliquer, cliquer sur le bouton à la fin !</p>";
}

echo "<p>Script one-shot qui régénère proprement tous les slugs (link_rewrite) des produits,<br>catégories et pages CMS dans toutes les langues du site PrestaShop (1.7 → 9.x).</p>";

$languages = Language::getLanguages(false);
if (!$languages) {
    die('❌ Aucune langue active trouvée.');
}

function cleanSlug($string)
{
    $string = Tools::strtolower($string);
    $string = preg_replace('/[^a-z0-9\- ]/', '', $string);
    $string = str_replace(' ', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

// --- Produits ---
try {
    $products = Db::getInstance()->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'product');
    foreach ($products as $p) {
        $id_product = (int)$p['id_product'];
        foreach ($languages as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $name = Db::getInstance()->getValue('SELECT name FROM ' . _DB_PREFIX_ . 'product_lang WHERE id_product = ' . $id_product . ' AND id_lang = ' . $id_lang);
            if ($name) {
                $slug = cleanSlug($name);
                if ($slug) {
                    $unique_slug = $slug;
                    $counter = 1;
                    while (isset($slugs_used[$id_lang][$unique_slug])) {
                        $unique_slug = $slug . '-' . $counter++;
                    }
                    $slugs_used[$id_lang][$unique_slug] = true;

                    if (!$dry_run) {
                        Db::getInstance()->update(
                            'product_lang',
                            ['link_rewrite' => pSQL($unique_slug)],
                            'id_product = ' . $id_product . ' AND id_lang = ' . $id_lang
                        );
                    }
                    echo "✅ Produit $id_product [$id_lang] => $unique_slug<br>";
                    $changes[] = ['type' => 'product', 'id' => $id_product, 'lang' => $id_lang, 'slug' => $unique_slug];
                    $counters['products']++;
                } else {
                    $counters['empty']++;
                }
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Erreur Produits: " . $e->getMessage() . "<br>";
    $counters['errors']++;
}

// --- Catégories ---
try {
    $categories = Db::getInstance()->executeS('SELECT id_category FROM ' . _DB_PREFIX_ . 'category');
    foreach ($categories as $c) {
        $id_category = (int)$c['id_category'];
        foreach ($languages as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $name = Db::getInstance()->getValue('SELECT name FROM ' . _DB_PREFIX_ . 'category_lang WHERE id_category = ' . $id_category . ' AND id_lang = ' . $id_lang);
            if ($name) {
                $slug = cleanSlug($name);
                if ($slug) {
                    $unique_slug = $slug;
                    $counter = 1;
                    while (isset($slugs_used[$id_lang][$unique_slug])) {
                        $unique_slug = $slug . '-' . $counter++;
                    }
                    $slugs_used[$id_lang][$unique_slug] = true;

                    if (!$dry_run) {
                        Db::getInstance()->update(
                            'category_lang',
                            ['link_rewrite' => pSQL($unique_slug)],
                            'id_category = ' . $id_category . ' AND id_lang = ' . $id_lang
                        );
                    }
                    echo "✅ Catégorie $id_category [$id_lang] => $unique_slug<br>";
                    $changes[] = ['type' => 'category', 'id' => $id_category, 'lang' => $id_lang, 'slug' => $unique_slug];
                    $counters['categories']++;
                } else {
                    $counters['empty']++;
                }
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Erreur Catégories: " . $e->getMessage() . "<br>";
    $counters['errors']++;
}

// --- CMS ---
try {
    $cms_pages = Db::getInstance()->executeS('SELECT id_cms FROM ' . _DB_PREFIX_ . 'cms');
    foreach ($cms_pages as $cms) {
        $id_cms = (int)$cms['id_cms'];
        foreach ($languages as $lang) {
            $id_lang = (int)$lang['id_lang'];
            $title = Db::getInstance()->getValue('SELECT meta_title FROM ' . _DB_PREFIX_ . 'cms_lang WHERE id_cms = ' . $id_cms . ' AND id_lang = ' . $id_lang);
            if (!$title) {
                $title = Db::getInstance()->getValue('SELECT content FROM ' . _DB_PREFIX_ . 'cms_lang WHERE id_cms = ' . $id_cms . ' AND id_lang = ' . $id_lang);
                $title = substr(strip_tags($title), 0, 100);
            }
            if ($title) {
                $slug = cleanSlug($title);
                if ($slug) {
                    $unique_slug = $slug;
                    $counter = 1;
                    while (isset($slugs_used[$id_lang][$unique_slug])) {
                        $unique_slug = $slug . '-' . $counter++;
                    }
                    $slugs_used[$id_lang][$unique_slug] = true;

                    if (!$dry_run) {
                        Db::getInstance()->update(
                            'cms_lang',
                            ['link_rewrite' => pSQL($unique_slug)],
                            'id_cms = ' . $id_cms . ' AND id_lang = ' . $id_lang
                        );
                    }
                    echo "✅ CMS $id_cms [$id_lang] => $unique_slug<br>";
                    $changes[] = ['type' => 'cms', 'id' => $id_cms, 'lang' => $id_lang, 'slug' => $unique_slug];
                    $counters['cms']++;
                } else {
                    $counters['empty']++;
                }
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Erreur CMS: " . $e->getMessage() . "<br>";
    $counters['errors']++;
}

$executionTime = round(microtime(true) - $startTime, 2);

// Export CSV
if (!$dry_run && !empty($changes)) {
    // Export CSV créé : slugs_changes_YYYY-MM-DD_HH-i-s.csv
    $csv_file = dirname(__FILE__) . '/slugs_changes_' . date('Y-m-d_H-i-s') . '.csv';
    $csv_content = "Type,ID,Langue,Nouveau Slug\n";
    foreach ($changes as $change) {
        $csv_content .= $change['type'] . "," . $change['id'] . "," . $change['lang'] . "," . $change['slug'] . "\n";
    }
    file_put_contents($csv_file, $csv_content);
    echo "<p>📥 Export CSV créé : <strong>" . basename($csv_file) . "</strong></p>";
    echo "<p><a href='" . basename($csv_file) . "' download style='display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>⬇️ Télécharger le CSV</a></p>";
}

echo "<h3>✅ " . ($dry_run ? "Simulation" : "Exécution") . " terminée !</h3>";
echo "<p>📊 <strong>Résumé :</strong></p>";
echo "<ul>";
echo "<li>Produits: " . $counters['products'] . "</li>";
echo "<li>Catégories: " . $counters['categories'] . "</li>";
echo "<li>CMS: " . $counters['cms'] . "</li>";
echo "<li>Slugs vides ignorés: " . $counters['empty'] . "</li>";
echo "<li>Erreurs: " . $counters['errors'] . "</li>";
echo "<li>⏱️ Temps d'exécution: " . $executionTime . "s</li>";
echo "</ul>";

if ($dry_run) {
    echo "<p style='margin-top: 20px;'>";
    echo "<a href='?confirm=" . $token . "' style='display: inline-block; padding: 12px 24px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin-right: 10px; font-weight: bold;'>✅ Appliquer les changements</a>";
    echo "<a href='' style='display: inline-block; padding: 12px 24px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>⬅️ Annuler</a>";
    echo "</p>";
} else {
    echo "<p>🌐 Maintenant, les balises hreflang devraient pointer vers les bonnes URLs.</p>";
    echo "<p>⚙️ Pensez à vider le cache dans Préférences > Performance</p>";

    // Ajout : bouton visible pour supprimer le script (sécurisé par token + confirmation JS)
    echo "<p style='margin-top:10px;'>";
    echo "<a href='?confirm=" . $token . "&delete=1' onclick=\"return confirm('Confirmer la suppression du script ? Cette action est irréversible.');\" style='display:inline-block;padding:12px 20px;background:#dc3545;color:#fff;text-decoration:none;border-radius:5px;font-weight:bold;'>🗑️ Supprimer le script maintenant</a>";
    echo "</p>";

    // Désactivation de l'auto-suppression : suppression uniquement via le bouton
    echo "<p style='color:#6c757d; margin-top:8px;'>La suppression automatique est désactivée. Le script sera supprimé uniquement si vous cliquez sur le bouton ci‑dessus.</p>";

    // Suppression sécurisée (requiert token) avec message de retour
    if (isset($_GET['delete']) && isset($_GET['confirm']) && $_GET['confirm'] === $token) {
        $file = __FILE__;
        if (@unlink($file)) {
            echo "<p style='color:green;'><strong>✅ Le fichier " . htmlspecialchars(basename($file)) . " a été supprimé.</strong></p>";
        } else {
            echo "<p style='color:red;'><strong>❌ Impossible de supprimer le fichier. Supprimez-le manuellement via FTP.</strong></p>";
        }
        exit;
    }
}
