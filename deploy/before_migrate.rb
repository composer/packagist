template "#{release_path}/app/config/parameters.yml" do
  source "#{release_path}/deploy/parameters.yml.erb"
  owner "apache"
  group "apache"
  mode "0644"
  local true
  variables(
    :application => node[:deploy][application]
  )
end

composer_project "#{release_path}" do
    dev false
    quiet true
    prefer_dist false
    action :install
end

execute "change-permission-cache" do
  command "chown -R 777:777 #{release_path}/cache"
  user "root"
  action :nothing
end
        
execute "change-permission-logs" do
  command "chown -R 777:777 #{release_path}/logs"
  user "root"
  action :nothing
end
