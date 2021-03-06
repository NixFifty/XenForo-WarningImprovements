/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

/**
 * Create the SV namespace, if it does not already exist.
 */
var SV = SV || {};

/** @param {jQuery} $ jQuery Object */
!function($, window, document, _undefined)
{
  /**
   * Allow toggling between select and radio views for choosing warnings.
   */
  SV.WarningViewToggler = function($toggler) { this.__construct($toggler); };
  SV.WarningViewToggler.prototype =
  {
    __construct: function($toggler)
    {
      this.$toggler = $toggler;

      this.$selectView = $toggler.siblings($toggler.data('selectview'));
      this.$radioView = $toggler.siblings($toggler.data('radioview'));

      this.phrases = {
        'toggleSelect': $toggler.data('toggleselecttext'),
        'toggleRadio':  $toggler.data('toggleradiotext')
      };

      this.storageName = 'xf_sv_warningview';
      this.setting = localStorage.getItem(this.storageName);

      this.init();

      $toggler.on('click', $.context(this, 'eClick'));
    },

    init: function()
    {
      if (!this.setting || !this.setting.length)
      {
        this.setSetting('select');
      }

      if (this.setting == 'select')
      {
        this.$selectView.show();
        this.$radioView.remove();
        this.$toggler.text(this.phrases.toggleRadio);
      }
      else
      {        
        this.$selectView.remove();
        this.$toggler.text(this.phrases.toggleSelect);
      }
    },

    toggle: function()
    {
      if (this.setting == 'select')
      {
        this.setSetting('radio');
      }
      else
      {
        this.setSetting('select');
      }

      window.location.reload();
    },

    setSetting: function(value)
    {
      this.setting = value;
      localStorage.setItem(this.storageName, this.setting);
    },

    eClick: function(e)
    {
      e.preventDefault();
      this.toggle();
    }
  };

  /**
   * Create a Chosen instance and handle change events.
   */
  SV.WarningSelector = function($selector) { this.__construct($selector); };
  SV.WarningSelector.prototype =
  {
    __construct: function($selector)
    {
      this.$selector = $selector;

      this.$customWarningTitle = $selector.siblings($selector.data(
        'customwarningtitle'
      ));

      this.phrases = {
        'noresults':   $selector.data('noresultstext'),
        'placeholder': $selector.data('placeholdertext')
      };

      this.init();

      $selector.on('change', $.context(this, 'eChange'));
    },

    init: function()
    {
      this.$selector.chosen({
        'width':                   '100%',
        'inherit_select_classes':  true,
        'no_result_text':          this.phrases.noresults,
        'placeholder_text_single': this.phrases.placeholder
      });
    },

    eChange: function(e)
    {
      var id = $(e.target).val();

      this.$selector.find('option[value="'+id+'"]').trigger('click');

      if (id == 0)
      {
        this.$customWarningTitle.show();

        setTimeout($.context(function()
        {
          this.$customWarningTitle.focus();
        }, this), 100);
      }
      else
      {
        this.$customWarningTitle.hide();
      }
    }
  };

  /**
   * Create a jsTree instance and handle events.
   */
  SV.WarningItemTree = function($tree) { this.__construct($tree); };
  SV.WarningItemTree.prototype =
  {
    __construct: function($tree)
    {
      this.$tree = $tree;

      this.overlay = $tree.data('overlay');
      this.$searchInput = $($tree.data('searchinput'));

      this.urls = {
        'load':           $tree.data('loadurl'),
        'sync':           $tree.data('syncurl'),
        'rename':         $tree.data('renameurl'),
        'categoryEdit':   $tree.data('categoryediturl'),
        'categoryDelete': $tree.data('categorydeleteurl'),
        'warningEdit':    $tree.data('warningediturl'),
        'warningDelete':  $tree.data('warningdeleteurl'),
      };

      this.phrases = {
        'rename': $tree.data('renametext'),
        'edit':   $tree.data('edittext'),
        'delete': $tree.data('deletetext')
      };

      this.init();
      this.initSearch();

      $tree.on('ready.jstree', $.context(this, 'eReady'));
      $tree.on('move_node.jstree', $.context(this, 'eMoveNode'));
      $tree.on('rename_node.jstree', $.context(this, 'eRenameNode'));
    },

    init: function()
    {
      XenForo.ajax(this.urls.load, '', $.context(function(ajaxData)
      {
        this.$tree.jstree({
          'plugins': [
            'contextmenu',
            'dnd',
            'search',
            'types',
            'wholerow'
          ],
          'core': {
            'data': ajaxData['tree'],
            'check_callback': function (operation, node, parent)
            {
              if (operation == 'rename_node')
              {
                var id = node.id.substr(1);
                if (id == 0)
                {
                  return false;
                }

                return true;
              }

              if (operation == 'move_node')
              {
                if (node.type == 'category')
                {
                  if (parent.parent != '#' && parent.parent != null)
                  {
                    return false;
                  }
                }

                return true;
              }

              return false;
            },
            'multiple': false,
          },
          'contextmenu': {
            'items': {
              'rename': {
                'label': this.phrases.rename,
                '_disabled': function(data)
                {
                  var inst = $.jstree.reference(data.reference);
                  var node = inst.get_node(data.reference);
                  var id = node.id.substr(1);

                  if (id == 0)
                  {
                    return true;
                  }

                  return false;
                },
                'action': function(data)
                {
                  var inst = $.jstree.reference(data.reference);
                  var node = inst.get_node(data.reference);

                  inst.edit(node);
                }
              },
              'edit': {
                'label': this.phrases.edit,
                'action': $.context(function(data)
                {
                  var inst = $.jstree.reference(data.reference);
                  var node = inst.get_node(data.reference);
                  var id = node.id.substr(1);

                  var href;
                  if (node.type == 'category')
                  {
                    href = this.urls.categoryEdit.replace('{id}', id);
                  }
                  else if (node.type == 'definition')
                  {
                    href = this.urls.warningEdit.replace('{id}', id);
                  }

                  window.location = XenForo.canonicalizeUrl(href);
                }, this)
              },
              'delete': {
                'label': this.phrases.delete,
                '_disabled': function(data)
                {
                  var inst = $.jstree.reference(data.reference);
                  var node = inst.get_node(data.reference);
                  var id = node.id.substr(1);

                  if (id == 0)
                  {
                    return true;
                  }

                  return false;
                },
                'action': $.context(function(data)
                {
                  var inst = $.jstree.reference(data.reference);
                  var node = inst.get_node(data.reference);
                  var id = node.id.substr(1);

                  var href;
                  if (node.type == 'category')
                  {
                    href = this.urls.categoryDelete.replace('{id}', id);
                  }
                  else if (node.type == 'definition')
                  {
                    href = this.urls.warningDelete.replace('{id}', id);
                  }

                  var overlay = new XenForo.OverlayLoader(
                    $({'href': href}),
                    false,
                    {'speed': XenForo.speed.fast}
                  );
                  overlay.load();
                }, this)
              }
            }
          },
          'dnd': {
            'copy': false,
            'is_draggable': true,
            'touch': 'selected',
            'large_drop_target': true,
            'large_drag_target': true
          },
          'search': {
            'show_only_matches': true
          },
          'state': {
            'key': 'xf_sv_warningitemtree'
          },
          'types': {
            '#': {
              'max_depth': 3,
              'valid_children': ['category']
            },
            'category': {
              'max_depth': 2,
              'valid_children': ['category', 'definition']
            },
            'definition': {
              'icon': 'jstree-file',
              'max_depth': 0,
              'valid_children': []
            }
          }
        });
      }, this));
    },

    initSearch: function()
    {
      var timeout = false;
      this.$searchInput.keyup($.context(function()
      {
        if (timeout)
        {
          clearTimeout(timeout);
        }

        timeout = setTimeout($.context(function()
        {
          var query = this.$searchInput.val();
          this.$tree.jstree(true).search(query);
        }, this), 250);
      }, this));
    },

    sync: function()
    {
      var formData = {
        'tree': this.$tree.jstree(true).get_json('#', {'flat': true})
      };

      XenForo.ajax(this.urls.sync, formData, function()
      {
        console.log('Tree synchronized');
      });
    },

    handleLast: function()
    {
      if (window.location.hash)
      {
        var last = window.location.hash.replace('#_', '');

        var id;
        if (last.indexOf('warning-') == 0)
        {
          id = last.replace('warning-', 'd');
        }
        else if (last.indexOf('category-') == 0)
        {
          id = last.replace('category-', 'c');
        }

        if (id)
        {
          this.$tree.jstree(true).select_node(id);
        }
      }
    },

    eReady: function()
    {
      this.sync();
      if (this.overlay)
      {
        this.$tree.find('.jstree-anchor').addClass('OverlayTrigger');
        this.$tree.xfActivate();
      }

      if (localStorage.getItem('xf_sv_warningitemtree') === null)
      {
        this.$tree.jstree(true).open_all();
      }

      this.handleLast();
    },

    eMoveNode: function()
    {
      this.sync();
    },

    eRenameNode: function(e, data)
    {
      var formData = {
        'node': data.node
      };

      XenForo.ajax(this.urls.rename, formData, function()
      {
        console.log('Node renamed');
      });
    }
  };

  // *********************************************************************

  XenForo.register('a.WarningViewToggler',   'SV.WarningViewToggler');
  XenForo.register('select.WarningSelector', 'SV.WarningSelector');
  XenForo.register('.WarningItemTree',       'SV.WarningItemTree');
}
(jQuery, this, document);
