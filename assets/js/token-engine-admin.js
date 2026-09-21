( function () {
    'use strict';

    function copyValue( button ) {
        var target = document.getElementById( button.getAttribute( 'data-copy-target' ) );
        if ( ! target ) {
            return;
        }
        var done = function () {
            button.textContent = 'Copiée';
            window.setTimeout( function () { button.textContent = 'Copier'; }, 1800 );
        };
        if ( navigator.clipboard && window.isSecureContext ) {
            navigator.clipboard.writeText( target.value ).then( done );
            return;
        }
        target.focus();
        target.select();
        if ( document.execCommand( 'copy' ) ) {
            done();
        }
    }

    document.addEventListener( 'click', function ( event ) {
        if ( event.target && event.target.classList.contains( 'token-engine-copy' ) ) {
            copyValue( event.target );
        }
    } );

    function updateRuleScope( form ) {
        var scope = form.querySelector( '[data-token-engine-rule-scope]' );
        var project = form.querySelector( '[data-token-engine-rule-project]' );
        var select = form.querySelector( '[data-token-engine-rule-project-select]' );
        var globalHint = form.querySelector( '[data-token-engine-global-scope]' );
        if ( ! scope || ! project || ! select || ! globalHint ) {
            return;
        }
        var global = scope.value === 'global';
        project.hidden = global;
        select.disabled = global;
        select.required = ! global;
        globalHint.hidden = ! global;
    }

    function initializeRuleForms( root ) {
        ( root || document ).querySelectorAll( '[data-token-engine-rule-form]' ).forEach( function ( form ) {
            if ( form.dataset.tokenEngineRuleReady === '1' ) {
                updateRuleScope( form );
                return;
            }
            form.dataset.tokenEngineRuleReady = '1';
            var scope = form.querySelector( '[data-token-engine-rule-scope]' );
            if ( scope ) {
                scope.addEventListener( 'change', function () { updateRuleScope( form ); } );
            }
            updateRuleScope( form );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { initializeRuleForms( document ); } );
    } else {
        initializeRuleForms( document );
    }
}() );
