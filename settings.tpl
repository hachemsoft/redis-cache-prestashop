{$message}

<fieldset>
	<legend>Settings Redis Cache</legend>
	<form method="post">
            <p class="success">{l s='Please fill in the details about your redis server' mod='hsrediscache'}</p>
		<p>
			<label for="SRV_REDIS">Serveur:</label>
			<input id="SERVEUR_REDIS" name="SERVEUR_REDIS" type="text" value="{$SERVEUR_REDIS}" />
		</p>
		<p>
			<label for="PORT_REDIS">Port:</label>
			<input id="PORT_REDIS" name="PORT_REDIS" type="text" value="{$PORT_REDIS}" />
		</p>
		<p>
			<label>&nbsp;</label>
			<input id="submit_{$module_name}" name="submit_{$module_name}" type="submit" value="Save" class="button" />
		</p>
                
                <p>
                    
                    {l s='NB : Please check if Redis server is correctly installed on your server' mod='hsrediscache'} <a href="http://redis.io/download" target="_blank"/> {l s='( Redis Server website ) ' mod='hsrediscache'}</a>
                </p>
	</form>
</fieldset>
