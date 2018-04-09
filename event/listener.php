<?php
/**
 *
 * @package phpBB Extension - tas2580 SEO URLs
 * @copyright (c) 2016 tas2580 (https://tas2580.net)
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace tas2580\privacyprotection\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener
 */
class listener implements EventSubscriberInterface
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\event\dispatcher_interface */
	protected $phpbb_dispatcher;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var string phpbb_root_path */
	protected $phpbb_root_path;

	/** @var string php_ext */
	protected $php_ext;

	/**
	 * Constructor
	 *
	 * @param \phpbb\db\driver\driver_interface		$db						Database object
	 * @param \phpbb\event\dispatcher_interface		$phpbb_dispatcher
	 * @param \phpbb\user							$user					User Object
	 * @param \phpbb\template\template				$template				Template object
	 * @param \phpbb\request\request				$request				Request object
	 * @param string								$phpbb_root_path		phpbb_root_path
	 * @param string								$php_ext				php_ext
	 * @access public
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\event\dispatcher_interface $phpbb_dispatcher, \phpbb\user $user, \phpbb\template\template $template, \phpbb\request\request $request, $phpbb_root_path, $php_ext)
	{
		$this->db = $db;
		$this->phpbb_dispatcher = $phpbb_dispatcher;
		$this->user = $user;
		$this->template = $template;
		$this->request = $request;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Assign functions defined in this class to event listeners in the core
	 *
	 * @return array
	 * @static
	 * @access public
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.ucp_register_user_row_after'					=> 'ucp_register_user_row_after',
			'core.ucp_display_module_before'					=> 'ucp_display_module_before',
		);
	}

	/**
	 * Display option to allow massemail on register
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function ucp_register_user_row_after($event)
	{
		$user_row = $event['user_row'];
		$user_row['user_allow_massemail'] = $this->request->variable('massemail', 0);
		$event['user_row'] = $user_row;
	}

	/**
	 * Handle download of private data in UCP
	 *
	 * @param object $event The event object
	 * @return null
	 * @access public
	 */
	public function ucp_display_module_before($event)
	{
		switch ($event['mode'])
		{
			case 'front':
			case '': // The dirty code of phpBB can also use empty mode for the front page
				$this->user->add_lang_ext('tas2580/privacyprotection', 'ucp');
				$this->template->assign_vars(array(
					'U_DOWNLOAD_MY_DATA'		=> append_sid("{$this->phpbb_root_path}ucp.$this->php_ext", 'mode=profile_download'),
					'U_DOWNLOAD_MY_POSTS'		=> append_sid("{$this->phpbb_root_path}ucp.$this->php_ext", 'mode=post_download'),
				));
				break;

			case 'profile_download':
				// Select data from user table
				$sql = 'SELECT user_id, user_ip, user_regdate, username, user_email, user_lastvisit, user_posts, user_lang, user_timezone, user_dateformat,
						user_avatar, user_sig, user_jabber
					FROM ' .  USERS_TABLE . '
					WHERE user_id = ' . (int) $this->user->data['user_id'];
				$result = $this->db->sql_query($sql);
				$user_row = $this->db->sql_fetchrow($result);

				// Select data from profile fields table
				$sql = 'SELECT *
					FROM ' .  PROFILE_FIELDS_DATA_TABLE . '
					WHERE user_id = ' . (int) $this->user->data['user_id'];
				$result = $this->db->sql_query($sql);
				$profile_fields_row = $this->db->sql_fetchrow($result);

				// Select data from session table
				$sql = 'SELECT session_id, session_last_visit, session_ip, session_browser
					FROM ' .  SESSIONS_TABLE . '
					WHERE session_user_id = ' . (int) $this->user->data['user_id'];
				$result = $this->db->sql_query($sql);
				$session_row = $this->db->sql_fetchrow($result);

				// Merge all data
				$data = array_merge($user_row, $session_row, $profile_fields_row);

				/**
				 * Add or modify user data in tas2580 privacyprotection extension
				 *
				 * @event tas2580.privacyprotection_collect_data_after
				 * @var    array	data		The user data row
				 */
				$vars = array('data');
				extract($this->phpbb_dispatcher->trigger_event('tas2580.privacyprotection_collect_data_after', compact($vars)));

				header("Content-type: text/csv");
				header("Content-Disposition: attachment; filename=my_user_data.csv");
				header("Pragma: no-cache");
				header("Expires: 0");

				foreach ($data as $name => $value)
				{
					if (!empty($value))
					{
						$header[] = $name;
						$content[] = $this->escape($value);
					}
				}

				echo implode(', ', $header) . "\n";
				echo implode(', ', $content);
				exit;

			case 'post_download':

				header("Content-type: text/csv");
				header("Content-Disposition: attachment; filename=my_post_data.csv");
				header("Pragma: no-cache");
				header("Expires: 0");

				$fields = 'post_id, topic_id, forum_id, poster_ip, post_time, post_subject, post_text';
				echo $fields . "\n";
				$sql = 'SELECT ' . $fields . '
					FROM ' .  POSTS_TABLE . '
					WHERE poster_id = ' . (int) $this->user->data['user_id'];
				$result = $this->db->sql_query($sql);
				while($row = $this->db->sql_fetchrow($result))
				{
					$row['post_text'] = $this->escape($row['post_text']);
					$row['post_subject'] = $this->escape($row['post_subject']);
					echo implode(', ', $row) . "\n";
				}
				exit;
		}
	}

	/**
	 * If there is a quotation mark in the string we need to replace it with double quotation marks (RFC 4180)
	 *
	 * @param string $data
	 * @return string
	 */
	private function escape($data)
	{
		if (substr_count($data, '"'))
		{
			$data = str_replace('"', '""', $data);
		}
		return '"' . $data . '"';
	}
}