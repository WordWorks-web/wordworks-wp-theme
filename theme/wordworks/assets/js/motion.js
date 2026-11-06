/**
 * Word Works: scroll reveals and current-menu highlighting.
 * No dependencies. Reveals only run when <html> has .ww-motion (set in <head>
 * by the theme unless the visitor prefers reduced motion).
 */
( function () {
	'use strict';
	var d = document;
	var root = d.documentElement;

	/* Highlight the current page (and its parent section) in the menu. */
	var clean = function ( u ) {
		return u.replace( /[#?].*$/, '' ).replace( /\/+$/, '' );
	};
	var here = clean( window.location.href );
	d.querySelectorAll( '.ww-nav a.wp-block-navigation-item__content' ).forEach( function ( a ) {
		if ( clean( a.href ) !== here ) {
			return;
		}
		var li = a.closest( '.wp-block-navigation-item' );
		if ( li ) {
			li.classList.add( 'current-menu-item' );
			var parent = li.parentElement && li.parentElement.closest( '.wp-block-navigation-item' );
			if ( parent ) {
				parent.classList.add( 'current-menu-ancestor' );
			}
		}
		a.setAttribute( 'aria-current', 'page' );
	} );

	/* Scroll reveals. */
	if ( ! root.classList.contains( 'ww-motion' ) || ! ( 'IntersectionObserver' in window ) ) {
		return;
	}
	var finish = function ( el ) {
		// Hand the element back to its normal styles (hover lifts etc.).
		el.classList.remove( 'reveal', 'is-in' );
		el.style.removeProperty( '--ww-delay' );
	};
	var io = new IntersectionObserver(
		function ( entries ) {
			var i = 0;
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}
				var el = entry.target;
				io.unobserve( el );
				el.style.setProperty( '--ww-delay', Math.min( i++ * 90, 360 ) + 'ms' );
				el.classList.add( 'is-in' );
				var done = function () {
					finish( el );
				};
				el.addEventListener( 'transitionend', function ( e ) {
					if ( e.target === el ) {
						done();
					}
				} );
				window.setTimeout( done, 1400 );
			} );
		},
		{ rootMargin: '0px 0px -8% 0px', threshold: 0 }
	);
	d.querySelectorAll( '.reveal' ).forEach( function ( el ) {
		io.observe( el );
	} );
} )();
