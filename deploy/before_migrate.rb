template "#{release_path}/app/config/parameters.yml" do
  source "#{release_path}/deploy/parameters.yml.erb"
  owner "apache"
  group "apache"
  mode "0644"
  local true
end

composer_project "#{release_path}" do
    dev false
    quiet true
    prefer_dist false
    action :install
end

execute "change-permission-cache" do
  command "chown -R 777:777 #{release_path}/app/cache"
  user "root"
  action :nothing
end
        
execute "change-permission-logs" do
  command "chown -R 777:777 #{release_path}/app/logs"
  user "root"
  action :nothing
end

execute "install web assets" do
  command "app/console assets:install web"
  cwd "#{release_path}"
  user "root"
  action :nothing
end
