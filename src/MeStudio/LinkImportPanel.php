<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

final class LinkImportPanel
{
    public static function render(): void
    {
        ?>
        <details class="faluss-studio-v3__editor faluss-link-import" data-link-import>
            <summary class="faluss-link-import__entry">
                <span class="faluss-link-import__entry-icon" aria-hidden="true">↓</span>
                <span>Importer mes liens<small>CSV, JSON ou copier-coller</small></span>
                <span class="faluss-link-import__chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="faluss-link-import__body">
                <ol class="faluss-link-import__steps" aria-label="Étapes de l’import">
                    <li data-import-step="source" aria-current="step"><span aria-hidden="true">1</span> Ta source</li>
                    <li data-import-step="preview"><span aria-hidden="true">2</span> Tes liens</li>
                </ol>
                <div data-import-source-stage>
                    <p>Récupère tes liens depuis Linktree ou une autre plateforme.</p>
                    <div class="faluss-link-import__sources" role="tablist" aria-label="Source des liens">
                        <button id="faluss-import-tab-file" type="button" role="tab" aria-selected="true" aria-controls="faluss-import-panel-file" data-import-method="file">Un fichier</button>
                        <button id="faluss-import-tab-text" type="button" role="tab" aria-selected="false" aria-controls="faluss-import-panel-text" tabindex="-1" data-import-method="text">Copier-coller</button>
                    </div>
                    <div id="faluss-import-panel-file" role="tabpanel" aria-labelledby="faluss-import-tab-file" data-import-method-panel="file">
                        <label class="faluss-link-import__upload">
                            <span class="faluss-link-import__upload-icon" aria-hidden="true">↑</span>
                            <span class="faluss-link-import__file-name" data-import-file-name>Choisir un fichier</span>
                            <span class="faluss-link-import__hint">CSV ou JSON · 512 Ko max.</span>
                            <input class="faluss-link-import__file" type="file" accept=".csv,.json,text/csv,application/json" aria-label="Choisir un fichier CSV ou JSON" data-import-file>
                        </label>
                        <p class="faluss-link-import__hint">Jusqu’à 500 liens. Ton fichier n’est pas conservé.</p>
                    </div>
                    <div id="faluss-import-panel-text" role="tabpanel" aria-labelledby="faluss-import-tab-text" data-import-method-panel="text" hidden>
                        <label>Tes liens<textarea data-import-source rows="4" placeholder="Mon site https://exemple.fr&#10;Ma chaîne https://youtube.com/@exemple"></textarea></label>
                        <p class="faluss-link-import__hint">Colle du texte, du CSV, du JSON, du HTML ou du Markdown.</p>
                    </div>
                    <details class="faluss-link-import__options">
                        <summary>Options de détection</summary>
                        <label>Format<select data-import-format><option value="auto">Détection automatique</option><option value="csv">CSV</option><option value="json">JSON</option><option value="text">Texte / HTML / Markdown</option></select></label>
                    </details>
                    <button class="faluss-studio-v3__save faluss-link-import__primary" type="button" data-import-analyse disabled>Prévisualiser mes liens</button>
                    <p class="faluss-link-import__hint faluss-link-import__privacy">Aucun accès à ton compte tiers. Tes liens actuels restent en place.</p>
                </div>
                <p class="faluss-link-import__notice" data-import-notice role="status" aria-live="polite" hidden></p>
                <div data-import-preview hidden>
                    <div class="faluss-link-import__review-heading">
                        <h2 tabindex="-1" data-import-heading>Vérifie tes liens</h2>
                        <button class="faluss-link-import__back" type="button" data-import-back>Changer de source</button>
                    </div>
                    <p class="faluss-link-import__stats" data-import-summary></p>
                    <p class="faluss-link-import__hint">Décoche un lien pour l’exclure. Ouvre-le pour modifier son titre, son URL ou son ordre.</p>
                    <ol class="faluss-link-import__rows" data-import-rows></ol>
                    <div class="faluss-link-import__footer">
                        <p class="faluss-link-import__hint">Ajout hors collection, visible par tous. Tes liens actuels sont conservés.</p>
                        <button class="faluss-studio-v3__save faluss-link-import__primary" type="button" data-import-review hidden>Vérifier les modifications</button>
                        <button class="faluss-studio-v3__save faluss-link-import__primary" type="button" data-import-commit disabled>Importer mes liens</button>
                    </div>
                </div>
                <noscript>Active JavaScript pour prévisualiser et importer tes liens.</noscript>
            </div>
        </details>
        <?php
    }
}
